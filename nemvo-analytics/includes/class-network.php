<?php
/**
 * Visitor network classification.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Tells what kind of network a visit comes from: a company office, a home
 * connection, a mobile network, a datacenter (often bots and VPNs), a
 * university or a public authority.
 *
 * Input is the ASN, the ASN organisation and, when available, the reverse DNS
 * (PTR) hostname of the visitor IP. ISPs serve homes and companies from the
 * same ASN, so for them the hostname decides: generic names such as
 * "dyn-83-233-1-2.isp.net" or "...bbcust.telenor.se" are residential, while
 * "static"/"business" names and names in a non-ISP domain ("fw.acme.se")
 * are business. Pure string work: no network access and no database.
 */
class Network {

	const BUSINESS    = 'business';
	const RESIDENTIAL = 'residential';
	const ISP         = 'isp';
	const MOBILE      = 'mobile';
	const HOSTING     = 'hosting';
	const EDUCATION   = 'education';
	const GOVERNMENT  = 'government';

	/**
	 * Internal kind: the organisation name hints at an ISP but could also be
	 * an ordinary company ("... Communications", municipal energy companies
	 * running a city network).
	 */
	const KIND_MAYBE_ISP = 'maybe_isp';

	/**
	 * Internal kind: a named organisation that is none of the above, i.e. it
	 * owns its ASN.
	 */
	const KIND_OWNER = 'owner';

	/**
	 * Compiled patterns and lookup tables, built on first use.
	 *
	 * @var array|null
	 */
	private static $data = null;

	/**
	 * Classify a visitor network.
	 *
	 * @param int    $asn      Autonomous system number (0 when unknown).
	 * @param string $org      ASN organisation name ('' when unknown).
	 * @param string $hostname Reverse DNS hostname of the visitor IP ('' when none / not looked up).
	 * @return array{type:string,company:string,provider:string}
	 */
	public static function classify( $asn, $org, $hostname = '' ) {
		$provider = self::clean_org( $org );
		$kind     = self::kind( max( 0, (int) $asn ), $provider );
		$host     = self::normalise_host( $hostname );
		$info     = '' !== $host ? self::host_info( $host ) : null;

		if ( self::HOSTING === $kind ) {
			return self::result( self::HOSTING, '', $provider );
		}
		if ( self::EDUCATION === $kind || ( $info && self::EDUCATION === $info['sector'] ) ) {
			return self::result( self::EDUCATION, '', $provider );
		}
		if ( self::GOVERNMENT === $kind || ( $info && self::GOVERNMENT === $info['sector'] ) ) {
			return self::result( self::GOVERNMENT, '', $provider );
		}

		if ( self::MOBILE === $kind ) {
			// A mobile operator: only a company hostname (fixed-IP business
			// subscriptions) turns the visit into a business visit.
			$result = $info ? self::from_hostname( $info, $provider, false ) : null;
			if ( $result && self::BUSINESS === $result['type'] ) {
				return $result;
			}
			return self::result( self::MOBILE, '', $provider );
		}

		if ( self::ISP === $kind || self::KIND_MAYBE_ISP === $kind ) {
			if ( ! $info ) {
				return self::result( self::ISP, '', $provider );
			}
			return self::from_hostname( $info, $provider, self::KIND_MAYBE_ISP === $kind );
		}

		if ( self::KIND_OWNER === $kind ) {
			// An unlisted ISP still gives itself away with generic customer
			// hostnames that embed the IP address.
			if ( $info && $info['embedded'] && $info['mobile'] ) {
				return self::result( self::MOBILE, '', $provider );
			}
			if ( $info && $info['embedded'] && $info['residential'] ) {
				return self::result( self::RESIDENTIAL, '', $provider );
			}
			return self::result( self::BUSINESS, $provider, $provider );
		}

		// Nothing known about the network: only clear hostname markers count.
		if ( $info ) {
			if ( $info['mobile'] ) {
				return self::result( self::MOBILE, '', $provider );
			}
			if ( $info['residential'] || $info['embedded'] ) {
				return self::result( self::RESIDENTIAL, '', $provider );
			}
			if ( self::is_known_isp_domain( $info['domain'] ) ) {
				return self::result( self::ISP, '', $provider );
			}
		}
		return self::result( '', '', $provider );
	}

	/**
	 * Whether a reverse DNS lookup could change the result for this ASN/org
	 * (true for ISPs, mobile operators and unknown networks; false for
	 * hosting, education, government and organisations that own their ASN).
	 * Used to skip DNS queries when they are pointless.
	 *
	 * @param int    $asn Autonomous system number (0 when unknown).
	 * @param string $org ASN organisation name ('' when unknown).
	 * @return bool
	 */
	public static function needs_hostname( $asn, $org ) {
		$kind = self::kind( max( 0, (int) $asn ), self::clean_org( $org ) );
		return in_array( $kind, array( self::ISP, self::MOBILE, self::KIND_MAYBE_ISP, '' ), true );
	}

	/**
	 * Registrable domain of a hostname: 'fw.acme.co.uk' => 'acme.co.uk',
	 * 'gw.volvo.com' => 'volvo.com'. Knows the common multi-part public
	 * suffixes (co.uk, com.au, co.jp, ...) and treats ac/co/com/edu/gov/net/
	 * org-style second levels under country domains as suffixes.
	 *
	 * @param string $hostname Hostname.
	 * @return string Lowercase domain, or '' for IP addresses and invalid names.
	 */
	public static function registrable_domain( $hostname ) {
		$host = self::normalise_host( $hostname );
		if ( '' === $host ) {
			return '';
		}
		$labels = explode( '.', $host );
		$count  = count( $labels );
		$data   = self::data();
		$tld    = $labels[ $count - 1 ];
		$second = $labels[ $count - 2 ];
		$suffix = isset( $data['suffixes'][ $second . '.' . $tld ] ) || ( 2 === strlen( $tld ) && isset( $data['slds'][ $second ] ) );
		if ( $suffix ) {
			return $count >= 3 ? $labels[ $count - 3 ] . '.' . $second . '.' . $tld : '';
		}
		return $second . '.' . $tld;
	}

	/**
	 * Translatable labels for the types, for the UI.
	 *
	 * @return array type => label.
	 */
	public static function types() {
		return array(
			self::BUSINESS    => __( 'Business', 'nemvo-analytics' ),
			self::RESIDENTIAL => __( 'Residential', 'nemvo-analytics' ),
			self::ISP         => __( 'ISP (home or office)', 'nemvo-analytics' ),
			self::MOBILE      => __( 'Mobile network', 'nemvo-analytics' ),
			self::HOSTING     => __( 'Hosting / VPN', 'nemvo-analytics' ),
			self::EDUCATION   => __( 'Education / research', 'nemvo-analytics' ),
			self::GOVERNMENT  => __( 'Government', 'nemvo-analytics' ),
		);
	}

	/**
	 * Classify an ISP (or mobile operator) visit by its hostname.
	 *
	 * @param array  $info      Output of host_info().
	 * @param string $provider  Cleaned ASN organisation.
	 * @param bool   $maybe_isp Organisation is only possibly an ISP.
	 * @return array{type:string,company:string,provider:string}
	 */
	private static function from_hostname( array $info, $provider, $maybe_isp ) {
		if ( $info['mobile'] ) {
			return self::result( self::MOBILE, '', $provider );
		}
		$domain   = $info['domain'];
		$known    = self::is_known_isp_domain( $domain );
		$own      = '' === $domain || $known || self::is_org_domain( $domain, $provider );
		$customer = $own ? '' : $domain;
		if ( $info['business'] ) {
			// "static-1-2-3-4.business.isp.net" is the ISP's generic name for a
			// business line; "office.acme.se" names the company.
			return self::result( self::BUSINESS, $info['embedded'] ? '' : $customer, $provider );
		}
		if ( $info['residential'] || $info['embedded'] ) {
			return self::result( self::RESIDENTIAL, '', $provider );
		}
		if ( '' !== $customer ) {
			return self::result( self::BUSINESS, $customer, $provider );
		}
		if ( $maybe_isp && ! $known && '' !== $domain ) {
			// "gw.axis.com" on "Axis Communications AB": the company itself.
			return self::result( self::BUSINESS, $provider, $provider );
		}
		return self::result( self::ISP, '', $provider );
	}

	/**
	 * What the ASN / organisation is, in rule order: hosting, education,
	 * government, mobile, ISP, possibly an ISP, other organisation.
	 *
	 * @param int    $asn      Autonomous system number.
	 * @param string $provider Cleaned organisation name.
	 * @return string One of the type constants, KIND_MAYBE_ISP, KIND_OWNER or '' (unknown).
	 */
	private static function kind( $asn, $provider ) {
		$data   = self::data();
		$by_asn = isset( $data['asns'][ $asn ] ) ? $data['asns'][ $asn ] : '';
		foreach ( $data['org'] as $kind => $regex ) {
			if ( $kind === $by_asn || ( '' !== $provider && preg_match( $regex, $provider ) ) ) {
				return $kind;
			}
		}
		return '' === $provider ? '' : self::KIND_OWNER;
	}

	/**
	 * Signals in a hostname.
	 *
	 * @param string $host Normalised hostname.
	 * @return array{domain:string,sector:string,mobile:bool,business:bool,residential:bool,embedded:bool}
	 */
	private static function host_info( $host ) {
		$data   = self::data();
		$domain = self::registrable_domain( $host );
		$labels = explode( '.', $host );
		$count  = count( $labels );
		$tld    = $labels[ $count - 1 ];
		$second = $labels[ $count - 2 ];
		$cctld  = 2 === strlen( $tld );

		$sector = '';
		if ( 'edu' === $tld || ( $cctld && isset( $data['edu_slds'][ $second ] ) ) ) {
			$sector = self::EDUCATION;
		} elseif ( 'gov' === $tld || 'mil' === $tld || ( $cctld && isset( $data['gov_slds'][ $second ] ) ) || 'europa.eu' === $domain ) {
			$sector = self::GOVERNMENT;
		}

		// Markers are only looked for left of the registrable domain, so the
		// company or ISP name itself ("client.se", "bredband2.net") never counts.
		$sub    = '' !== $domain ? (string) substr( $host, 0, -strlen( $domain ) ) : $host;
		$tokens = ' ' . implode( ' ', preg_split( '/[._-]+/', $sub, -1, PREG_SPLIT_NO_EMPTY ) ) . ' ';

		return array(
			'domain'      => $domain,
			'sector'      => $sector,
			'mobile'      => (bool) preg_match( $data['host']['mobile'], $tokens ),
			'business'    => (bool) preg_match( $data['host']['business'], $tokens ) || false !== strpos( $host, 'business' ),
			'residential' => (bool) preg_match( $data['host']['residential'], $tokens ) || (bool) preg_match( '/(?:^|[.-])ip-?\d/', $sub ),
			'embedded'    => self::embeds_ip( $sub ),
		);
	}

	/**
	 * Whether a hostname part embeds an IP address, as ISPs' generic customer
	 * names do: 83-233-1-2, 83.233.1.2 (either order), 083233001002,
	 * p5b0a1b2c (hex) or 2a02-aa1-1-2--3 (IPv6 groups).
	 *
	 * @param string $sub Hostname part left of the registrable domain.
	 * @return bool
	 */
	private static function embeds_ip( $sub ) {
		if ( '' === $sub ) {
			return false;
		}
		if ( preg_match_all( '/(?<![0-9])(\d{1,3})[.-](\d{1,3})[.-](\d{1,3})[.-](\d{1,3})(?![0-9])/', $sub, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				if ( max( (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4] ) <= 255 ) {
					return true;
				}
			}
		}
		if ( preg_match( '/(?<![0-9])(?:[01]\d\d|2[0-4]\d|25[0-5]){4}(?![0-9])/', $sub ) ) {
			return true;
		}
		if ( preg_match( '/(?:^|[.-])[a-z]?([0-9a-f]{8})(?:[.-]|$)/', $sub, $m ) && preg_match_all( '/\d/', $m[1] ) >= 2 ) {
			return true;
		}
		return (bool) preg_match( '/(?:^|[.-])2[0-9a-f]{3}(?:-{1,2}[0-9a-f]{1,4}){3,}(?:[.-]|$)/', $sub );
	}

	/**
	 * Whether a domain is a known ISP reverse DNS domain.
	 *
	 * @param string $domain Registrable domain.
	 * @return bool
	 */
	private static function is_known_isp_domain( $domain ) {
		if ( '' === $domain ) {
			return false;
		}
		$data = self::data();
		if ( isset( $data['isp_domains'][ $domain ] ) ) {
			return true;
		}
		$label = (string) preg_replace( '/[^a-z0-9]/', '', strstr( $domain, '.', true ) );
		return isset( $data['isp_brands'][ $label ] );
	}

	/**
	 * Whether a domain belongs to the organisation itself: its first label and
	 * the organisation name contain one another ("telia.com" for "Telia
	 * Company AB", "dnainternet.net" for "DNA Oyj").
	 *
	 * @param string $domain   Registrable domain.
	 * @param string $provider Cleaned organisation name.
	 * @return bool
	 */
	private static function is_org_domain( $domain, $provider ) {
		$label = (string) preg_replace( '/[^a-z0-9]/', '', strstr( $domain, '.', true ) );
		if ( strlen( $label ) < 3 || '' === $provider ) {
			return false;
		}
		$data   = self::data();
		$tokens = array();
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $provider ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( strlen( $token ) >= 3 && ! isset( $data['stop_words'][ $token ] ) ) {
				$tokens[] = $token;
			}
		}
		$joined = implode( '', $tokens );
		if ( '' === $joined ) {
			return false;
		}
		if ( false !== strpos( $joined, $label ) || false !== strpos( $label, $joined ) ) {
			return true;
		}
		foreach ( $tokens as $token ) {
			if ( strlen( $token ) >= 4 && false !== strpos( $label, $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clean an ASN organisation name: trim, collapse whitespace, drop a
	 * leading "AS1234" or duplicated handle ("TELIANET Telia Company AB") and
	 * trailing handle suffixes ("BAHNHOF-AS", "AMAZON-02").
	 *
	 * @param string $org Organisation name.
	 * @return string At most 100 characters.
	 */
	private static function clean_org( $org ) {
		$org = (string) $org;
		if ( '' === $org ) {
			return '';
		}
		if ( ! preg_match( '//u', $org ) ) {
			$org = (string) preg_replace( '/[\x80-\xFF]/', '', $org );
		}
		$org = trim( (string) preg_replace( '/[\s\x00-\x1F\x7F]+/u', ' ', $org ) );
		$org = (string) preg_replace( '/^AS\d+\s+/i', '', $org );

		// "TELIANET Telia Company AB": an upper case handle repeating the name.
		if ( preg_match( '/^([A-Z0-9][A-Z0-9-]{2,})\s+(\S+)(.*)$/', $org, $m ) && preg_match( '/[a-z]/', $m[2] . $m[3] ) ) {
			$handle = strtolower( str_replace( '-', '', $m[1] ) );
			$first  = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $m[2] ) );
			if ( strlen( $first ) >= 3 && 0 === strpos( $handle, $first ) ) {
				$org = $m[2] . $m[3];
			}
		}

		if ( ! preg_match( '/[a-z]/', $org ) ) {
			// Pure handles: "AS-CHOOPA", "BAHNHOF-AS", "MICROSOFT-CORP-MSN-AS-BLOCK", "AMAZON-02".
			$org = (string) preg_replace( '/^AS-(?=[A-Z0-9])/', '', $org );
			$org = (string) preg_replace( '/[\s_-]+ASN?(-?\d+)?(-[A-Z0-9]+)*$/', '', $org );
			$org = (string) preg_replace( '/-\d+$/', '', $org );
		} else {
			$org = (string) preg_replace( '/-ASN?$/i', '', $org );
		}
		$org = trim( $org, " \t,;:-" );
		return mb_substr( $org, 0, 100 );
	}

	/**
	 * Normalise a hostname: lowercase, no trailing dot, valid characters only.
	 *
	 * @param string $hostname Hostname.
	 * @return string '' for IP addresses (gethostbyaddr() returns the IP when
	 *                there is no PTR) and anything that is not a hostname.
	 */
	private static function normalise_host( $hostname ) {
		$host = rtrim( trim( (string) $hostname ), '.' );
		if ( '' === $host || strlen( $host ) > 253 || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( preg_match( '/[^\x00-\x7F]/', $host ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) || ! defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
				return '';
			}
			$host = (string) idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		}
		$host = strtolower( $host );
		if ( ! preg_match( '/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host ) ) {
			return '';
		}
		return $host;
	}

	/**
	 * Build a result.
	 *
	 * @param string $type     Type.
	 * @param string $company  Company name or domain.
	 * @param string $provider Cleaned ASN organisation.
	 * @return array{type:string,company:string,provider:string}
	 */
	private static function result( $type, $company, $provider ) {
		return array(
			'type'     => $type,
			'company'  => mb_substr( $company, 0, 100 ),
			'provider' => $provider,
		);
	}

	/**
	 * Patterns and lookup tables (built once per request).
	 *
	 * @return array
	 */
	private static function data() {
		if ( null !== self::$data ) {
			return self::$data;
		}

		// Organisation name patterns, in rule order. Matched case-insensitively
		// against the cleaned name (UTF-8).
		$org = array(
			self::HOSTING        => array(
				// Clouds, CDNs and security proxies.
				'\bamazon\b', '\baws\b', '\bgoogle\b(?!\s*fiber)', '\bmicrosoft\b', '\bazure\b', '\boracle\b', 'softlayer', '\bibm\s*cloud',
				'alibaba', 'aliyun', '\btencent', 'huawei\s*clouds?', 'digital\s*ocean', '\blinode', '\bakamai', 'cloudflare', '\bfastly',
				'zscaler', 'stackpath', 'highwinds', '\bcdn77', 'datacamp', 'datapacket', 'equinix', 'rackspace', 'hurricane\s+electric',
				// Hosting and VPS providers.
				'\bvultr', '\bchoopa', 'the\s+constant\s+company', 'hetzner', '\bovh', 'scaleway', '\bonline\s+s\.?a\.?s\b', 'contabo',
				'leaseweb', '\bm247\b', 'go\s*daddy', '\bionos\b', '1\s*&\s*1\s+(internet|ionos)', '\bstrato\b', 'hostinger', 'namecheap',
				'\ba2\s+hosting', 'psychz', 'colocrossing', 'quadranet', '\btzulo', 'hostroyale', 'clouvider', '\bg-?core\b', 'kamatera',
				'upcloud', 'glesys', 'city\s*network', '\bbinero', '\bloopia', 'elastx', 'safe\s*host', '\bportlane', 'worldstream',
				'\bnforce\b', 'serverius', 'hivelocity', '\bselectel', '\btimeweb', '\baeza\b', 'stark\s+industries', 'frantech', '\bbuyvm',
				'hostwinds', 'interserver', 'liquid\s*web', 'unified\s+layer', 'bluehost', '\bone\.com\b', '\boderland', 'redpill\s*linpro',
				'hostkey', 'flokinet', 'melbicom', 'maxihost', 'latitude\.sh', 'wholesale\s+internet', '\bnocix', 'phoenixnap', 'sharktech',
				'\bwebnx', 'singlehop',
				// VPN operators and their infrastructure.
				'private\s+internet\s+access', '\bkape\s+tech', 'packethub', 'tefincom', '\bmullvad', '\b31173\s+services', '\bproton\s*(ag|vpn)\b',
				'surfshark', 'nordvpn', 'expressvpn', '\b1337\s+services', '\bnjalla',
				// Generic words.
				'hosting', '\bhost', 'cloud', 'data\s*-?\s*cent(er|re)', 'rechenzentrum', '\bserver', '\bvps\b', '\bcolo\b', 'colocation',
				'\bdedicated\b', '\bcdn', '\bvpn\b', '\bprox(y|ies)\b',
			),
			self::EDUCATION      => array(
				'universit', 'univerzit', 'uniwersytet', 'yliopisto', 'korkeakoulu', 'egyetem', '\bcollege', '\bschool', 'skola\b', 'skole\b',
				'koulu\b', 'gymnasi', '\bacadem(y|ie|ia|ic)\b(?!\s+work)', '\bakademi', 'hochschule', 'h[öo]gskol', 'h[øo]gskol', 'h[øo]yskol',
				'polytechn', 'politecnic', '\binstitut(e|et|o)?\b', 'fraunhofer', 'max[\s-]planck', 'research\s+(and\s+education\s+)?network',
				'research\s+(council|institutes?|cent(er|re))', 'forskningsn(e|æ)t', '\bnren\b', '\bsunet\b', 'nordunet', 'g[eé]ant\b', '\buninett\b',
				'\bsikt\b', '\bfunet\b', '\bdeic\b', '\bsurf\s*net\b', '\bsurf\b', '\bdfn\b', 'forschungsnetz', '\bjanet\b', '\bjisc\b', '\brenater\b',
				'internet2', '\brediris\b', '\bgarr\b', '\bbelnet\b', '\bcesnet\b', '\baarnet\b', '\bcanarie\b', '\beduroam\b', '\bedu\b',
				'karolinska', 'chalmers', '\bkth\b',
			),
			self::GOVERNMENT     => array(
				'\bgovernment', '\bgouvernement', '\bgobierno', '\bregierung', '\bregering', '\bgovt?\b', '\bministr', '\bminist[eè]re',
				'ministerie', 'ministerium', '\bdepartment\s+of\b', '\bmunicipal', '\bkommun(e|en)?\b', '\bkunta\b', '\bkaupunki\b', '\bstad\b',
				'\bcity\s+of\b', '\bcounty\s+(council|of|government)\b', '^region\s', 'regionen\b', 'landsting', 'fylkeskommune', '\bfederal\b',
				'\bstate\s+of\b', '\bpolice\b', '\bpolisen\b', 'polismyndighet', '\bpolitiet\b', '\bpoliisi', 'armed\s+forces', 'f[öo]rsvarsmakt',
				'\bforsvaret\b', 'forsvarsministeriet', 'puolustusvoimat', 'bundeswehr', '\bmilitary\b', '\barmy\b', '\bnavy\b', '\bair\s+force\b',
				'\bnhs\b', 'riksdag', 'regeringskansli', '\bstatens\b', 'skatteverket', 'f[öo]rs[äa]kringskassan', 'arbetsf[öo]rmedlingen',
				'\bmyndighet', '\bbundes(amt|ministerium|verwaltungsamt|anstalt|agentur)', '\bparliament', 'european\s+(commission|parliament)',
				'\bnato\b', 'united\s+nations',
			),
			self::MOBILE         => array(
				'\bmobil', 'wireless', 'cellular', '\bcellco\b', '\blte\b', '\b[345]g\b', '\bhi3g', '\bh3g\b', 'hutchison', '\bmvno\b', '\bgsm\b',
				'\bumts\b', 'comviq', '\bice\s+(communication|norge)', '\bee\s+(limited|ltd)\b', 'telef[oó]nica\s+uk',
			),
			self::ISP            => array(
				// Generic words.
				'telecom', 'telekom', 'tele\s?communica', 'telekommunika', 'broadband', 'bredb[aå]nd', '\bcable', 'kabel', '\bcatv\b', 'fib(er|re|ra)',
				'glasfaser', 'internet\s+service', '\bisp\b', 'telephone', 'telef[oó]nica', 'telefoni\b', '\btelco\b', 'stadsn[äe]t', '\bdsl\b',
				'\bftth\b',
				// Nordic.
				'\btelia', 'arelion', 'telenor', '\btele2\b', 'com\s*hem', 'bahnhof', 'ownit', 'ip[\s-]only', 'globalconnect', 'allente',
				'\bstofa\b', 'norlys', 'eniig', '\bfibia\b', '\bwaoo\b', 'yousee', '\btdc\b', 'nuuday', '\belisa\b', '\bdna\b', '\blyse\b', 'altibox',
				'\bget\s+(as|norway)\b', 'nextgentel', 'eidsiva', 'stokab', 'zitius', 'open\s+universe', '\bt3\b', 'tyfon', '\bhiper\b', 'kviknet',
				'kaisanet', 'lounea', '\banvia\b',
				// Europe.
				'\bkpn\b', 'ziggo', '\bodido\b', '\bupc\b', 'liberty\s+global', 'vodafone', 'versatel', 'movistar', '\borange\b', '\bsfr\b',
				'\bfree\s+(sas|s\.a\.s|mobile)\b', 'proxad', '\biliad\b', 'bouygues', '\bsky\s+(uk|italia|deutschland|broadband)\b', 'virgin\s+media',
				'talktalk', 'hyperoptic', '\bplusnet\b', '\bkcom\b', 'swisscom', '\ba1\s+telekom', 'proximus', 'belgacom', 'telenet', '\beir\b',
				'eircom', 'fastweb', '\btim\s+s\.?p\.?a', 'unitymedia', 'netcologne', '\bm-net\b', 'delta\s+fiber', '\bnetia\b', '\bvectra\b',
				// Rest of the world.
				'comcast', '\bcharter\s+comm', '\bspectrum\b', 'time\s+warner', 'verizon', 'at&t', '\bcox\s+comm', 'centurylink', '\blumen\b',
				'\bqwest\b', 'level\s*3', 'frontier\s+comm', 'windstream', 'cogent', 'mediacom', '\baltice\b', 'suddenlink', 'cincinnati\s+bell',
				'consolidated\s+comm', 'rogers', '\bbell\s+(canada|aliant)', '\bshaw\b', 'telus', 'videotron', 'telstra', '\boptus', '\btpg\b',
				'reliance\s+jio', '\bjio\b', 'airtel', 'bharti', 'starlink', 'space\s+exploration\s+tech', '\bviasat', '\bhughes\s+net',
			),
			self::KIND_MAYBE_ISP => array(
				'\bcommunications?\b', 'kommunikation', 'kommunikasjon', 'energi\b', 'kraft\b', 'elverk', 'stadsnet', 'citynet',
			),
		);
		foreach ( $org as $kind => $parts ) {
			$org[ $kind ] = '~' . implode( '|', $parts ) . '~iu';
		}

		// Curated ASNs (organisation names vary between databases).
		$asns = array();
		$list = array(
			self::HOSTING   => array( 16509, 14618, 8987, 15169, 396982, 19527, 8075, 8068, 31898, 36351, 45102, 37963, 132203, 45090, 136907, 14061, 63949, 20940, 16625, 20473, 24940, 16276, 12876, 51167, 60781, 28753, 16265, 9009, 60068, 212238, 13335, 54113, 53813, 62044, 26496, 8560, 6724, 47583, 22612, 55293, 40676, 36352, 8100, 62240, 199524, 42708, 43350, 49981, 207137, 136787, 39351, 6939, 46606 ),
			self::EDUCATION => array( 1653, 2603, 20965, 224, 1741, 1835, 1103, 680, 786, 2200, 11537, 766, 137, 2611, 559, 2852, 7575, 6509, 1930, 2107 ),
			self::MOBILE    => array( 21928, 22394, 6167, 20057 ),
			self::ISP       => array( 3301, 1299, 1759, 3292, 3308, 2119, 1257, 39651, 8473, 29518, 12552, 719, 16086, 29695, 1136, 33915, 9143, 3320, 3209, 6805, 3215, 15557, 12322, 5410, 2856, 5607, 5089, 13285, 7922, 20115, 701, 7018, 22773, 209, 3356, 5650, 812, 577, 6327, 1221, 4804, 3303, 8447, 5432, 6848, 5466, 12874, 3269, 55836, 9498, 6830, 14593 ),
		);
		foreach ( $list as $kind => $numbers ) {
			foreach ( $numbers as $number ) {
				$asns[ $number ] = $kind;
			}
		}

		// Hostname tokens (split on "." "-" "_"), optionally followed by digits.
		$host = array(
			'mobile'      => array( 'mobile', 'mobil', 'mob', 'mbl', 'mbb', 'mobileinternet', 'lte', '3g', '4g', '5g', 'gprs', 'umts', 'hspa', 'wap', 'gsm', 'cellular', 'wwan', 'ggsn', 'pgw', 'apn' ),
			'business'    => array( 'static', 'business', 'biz', 'bus', 'corp', 'corporate', 'company', 'enterprise', 'office', 'ent', 'dedicated', 'leased', 'sdsl', 'fixed', 'bedrift', 'foretag', 'erhverv', 'yritys' ),
			'residential' => array( 'dynamic', 'dyn', 'dynip', 'dip', 'dhcp', 'pool', 'ppp', 'pppoe', 'pppoa', 'dial', 'dialup', 'dialin', 'dsl', 'adsl', 'vdsl', 'xdsl', 'cable', 'catv', 'cm', 'cmts', 'cpe', 'hfc', 'docsis', 'gpon', 'fiber', 'fibre', 'ftth', 'fttx', 'fttb', 'fttc', 'fttp', 'fttn', 'bb', 'bbcust', 'broadband', 'bredband', 'cust', 'customer', 'customers', 'kund', 'kunde', 'kunder', 'asiakas', 'subscriber', 'subscribers', 'sub', 'subs', 'home', 'res', 'resi', 'resident', 'residential', 'user', 'users', 'client', 'clients', 'priv', 'private', 'privat', 'hsd', 'cgnat', 'cgn', 'ddns', 'dyndns' ),
		);
		foreach ( $host as $key => $words ) {
			$host[ $key ] = '/ (?:' . implode( '|', $words ) . ')\d* /';
		}

		// Known ISP reverse DNS domains, and ISP brands under any TLD.
		$isp_domains = array(
			'telia.com', 'telia.se', 'telia.net', 'telia.fi', 'telia.dk', 'telia.no', 'telenor.se', 'telenor.net', 'telenor.no', 'telenor.dk',
			'online.no', 'bredbandsbolaget.se', 'tele2.se', 'tele2.net', 'comhem.se', 'bahnhof.se', 'bahnhof.net', 'bredband2.net',
			'bredband2.com', 'ownit.se', 'ownit.net', 'ip-only.net', 'ip-only.se', 't3.se', 'tre.se', 'tre.dk', 'three.co.uk', 'tdc.net',
			'tdc.dk', 'yousee.dk', 'stofanet.dk', 'stofa.net', 'norlys.dk', 'fibia.dk', 'waoo.dk', 'elisa.fi', 'elisa.net', 'inet.fi',
			'dnainternet.net', 'dna.fi', 'altibox.net', 'lyse.net', 'get.no', 'nextgentel.net', 'ice.no', 'kpn.net', 'kpn.com', 'ziggo.nl',
			'chello.nl', 'chello.at', 't-ipconnect.de', 't-online.de', 't-dialin.net', 'telekom.de', 'dtag.de', 'kabel-deutschland.de',
			'unitymedia.de', 'versatel.de', 'arcor-ip.net', 'telefonica.de', 'orange.fr', 'wanadoo.fr', 'sfr.net', 'sfr.fr', 'proxad.net',
			'free.fr', 'bbox.fr', 'btcentralplus.com', 'bt.com', 'sky.com', 'skybroadband.com', 'virginm.net', 'virginmedia.com',
			'talktalk.net', 'plus.net', 'comcast.net', 'verizon.net', 'myvzw.com', 'rr.com', 'charter.com', 'spectrum.com', 'cox.net',
			'sbcglobal.net', 'att.net', 'centurylink.net', 'qwest.net', 'frontiernet.net', 'windstream.net', 'rogers.com', 'bell.ca',
			'sympatico.ca', 'shawcable.net', 'telus.net', 'videotron.ca', 'telstra.net', 'bigpond.net.au', 'optusnet.com.au', 'tpgi.com.au',
			'swisscom.ch', 'bluewin.ch', 'a1.net', 'telekom.at', 'skynet.be', 'proximus.be', 'telenet.be', 'eircom.net', 'fastwebnet.it',
			'telecomitalia.it', 'tim.it', 'starlinkisp.net',
		);
		$isp_brands  = array( 'telia', 'telenor', 'tele2', 'comhem', 'bahnhof', 'bredband2', 'ownit', 'iponly', 'globalconnect', 'vodafone', 'orange', 'elisa', 'kpn', 'ziggo', 'swisscom', 'proximus', 'telenet', 'comcast', 'verizon', 'telstra', 'sfr' );

		// Words in organisation names that say nothing about the domain.
		$stop_words = array( 'ab', 'as', 'asa', 'aps', 'oy', 'oyj', 'ag', 'gmbh', 'mbh', 'kg', 'sa', 'sas', 'spa', 'srl', 'bv', 'nv', 'ltd', 'limited', 'llc', 'inc', 'plc', 'corp', 'corporation', 'co', 'company', 'group', 'holding', 'holdings', 'the', 'of', 'and', 'och', 'og', 'und', 'des', 'international', 'services', 'service', 'solutions', 'networks', 'network', 'net', 'internet', 'communications', 'communication', 'telecom', 'telekom', 'telecommunications', 'broadband', 'bredband', 'cable', 'fiber', 'fibre', 'mobile', 'access', 'sverige', 'sweden', 'norge', 'norway', 'danmark', 'denmark', 'suomi', 'finland', 'deutschland', 'germany', 'nederland', 'netherlands', 'france', 'italia', 'espana', 'usa', 'europe', 'nordic', 'operations' );

		// Multi-part public suffixes beyond the generic ones in $slds.
		$suffixes = array( 'me.uk', 'ltd.uk', 'plc.uk', 'nhs.uk', 'police.uk', 'mod.uk', 'sch.uk', 'asn.au', 'id.au', 'school.nz', 'geek.nz', 'ad.jp', 'ed.jp', 'gr.jp', 'lg.jp', 're.kr', 'in.th', 'res.in', 'firm.in', 'gen.in', 'ind.in', 'nic.in', 'kommune.no', 'priv.no', 'mil.no', 'stat.no', 'dep.no', 'asso.fr' );
		$slds     = array( 'ac', 'co', 'com', 'edu', 'gob', 'gov', 'govt', 'gouv', 'go', 'gv', 'mil', 'net', 'org', 'or', 'ne', 'nom', 'sch' );

		self::$data = array(
			'org'         => $org,
			'asns'        => $asns,
			'host'        => $host,
			'isp_domains' => array_fill_keys( $isp_domains, true ),
			'isp_brands'  => array_fill_keys( $isp_brands, true ),
			'stop_words'  => array_fill_keys( $stop_words, true ),
			'suffixes'    => array_fill_keys( $suffixes, true ),
			'slds'        => array_fill_keys( $slds, true ),
			'edu_slds'    => array_fill_keys( array( 'ac', 'edu', 'sch', 'k12' ), true ),
			'gov_slds'    => array_fill_keys( array( 'gov', 'govt', 'gouv', 'gob', 'go', 'gv', 'mil', 'gc', 'kommune', 'stat', 'dep' ), true ),
		);
		return self::$data;
	}
}
