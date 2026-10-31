<?php
/**
 * Traffic source and channel attribution.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies a session's first hit into a channel (GA-style default channel
 * grouping, plus a dedicated "AI assistants" channel) and a readable source.
 */
class Referrer {

	const DIRECT      = 'Direct';
	const SEARCH      = 'Organic Search';
	const SOCIAL      = 'Organic Social';
	const AI          = 'AI Assistants';
	const EMAIL       = 'Email';
	const REFERRAL    = 'Referral';
	const PAID_SEARCH = 'Paid Search';
	const PAID_SOCIAL = 'Paid Social';
	const DISPLAY     = 'Display';
	const AFFILIATES  = 'Affiliates';
	const CAMPAIGNS   = 'Other Campaigns';

	/**
	 * Known hosts: regex => [display name, channel]. Order matters: specific
	 * Google/Microsoft properties come before the generic search engine rule.
	 *
	 * @return array
	 */
	private static function known_hosts() {
		return array(
			// AI assistants.
			'~(^|\.)(chatgpt\.com|chat\.openai\.com)$~'        => array( 'ChatGPT', self::AI ),
			'~(^|\.)perplexity\.ai$~'                         => array( 'Perplexity', self::AI ),
			'~(^|\.)claude\.ai$~'                             => array( 'Claude', self::AI ),
			'~^(gemini|bard)\.google\.com$~'                  => array( 'Gemini', self::AI ),
			'~^copilot\.(microsoft|cloud\.microsoft)\.com$|^copilot\.cloud\.microsoft$|^edgeservices\.bing\.com$~' => array( 'Copilot', self::AI ),
			'~(^|\.)chat\.deepseek\.com$~'                    => array( 'DeepSeek', self::AI ),
			'~(^|\.)chat\.mistral\.ai$~'                      => array( 'Mistral', self::AI ),
			'~(^|\.)(grok\.com|x\.ai)$~'                      => array( 'Grok', self::AI ),
			'~(^|\.)meta\.ai$~'                               => array( 'Meta AI', self::AI ),
			'~(^|\.)(you\.com|phind\.com|poe\.com)$~'         => array( 'AI search', self::AI ),
			// Email / webmail.
			'~^mail\.google\.com$|^com\.google\.android\.gm$~' => array( 'Gmail', self::EMAIL ),
			'~^outlook\.(live|office|office365)\.com$|^outlook\.cloud\.microsoft$~' => array( 'Outlook', self::EMAIL ),
			'~^mail\.yahoo\.com$|^webmail\.|^mail\.~'          => array( 'Webmail', self::EMAIL ),
			// Google properties that are not search.
			'~^(docs|drive|sites|groups)\.google\.com$~'      => array( 'Google Workspace', self::REFERRAL ),
			'~^news\.google\.com$~'                           => array( 'Google News', self::REFERRAL ),
			// Search engines.
			'~(^|\.)google\.[a-z.]{2,7}$|^com\.google\.android\.googlequicksearchbox$~' => array( 'Google', self::SEARCH ),
			'~(^|\.)bing\.com$~'                              => array( 'Bing', self::SEARCH ),
			'~(^|\.)duckduckgo\.com$~'                        => array( 'DuckDuckGo', self::SEARCH ),
			'~(^|\.)search\.yahoo\.com$|^yahoo\.com$~'        => array( 'Yahoo', self::SEARCH ),
			'~(^|\.)ecosia\.org$~'                            => array( 'Ecosia', self::SEARCH ),
			'~^search\.brave\.com$~'                          => array( 'Brave Search', self::SEARCH ),
			'~(^|\.)startpage\.com$~'                         => array( 'Startpage', self::SEARCH ),
			'~(^|\.)qwant\.com$~'                             => array( 'Qwant', self::SEARCH ),
			'~(^|\.)kagi\.com$~'                              => array( 'Kagi', self::SEARCH ),
			'~(^|\.)yandex\.[a-z.]{2,7}$|(^|\.)ya\.ru$~'      => array( 'Yandex', self::SEARCH ),
			'~(^|\.)baidu\.com$~'                             => array( 'Baidu', self::SEARCH ),
			'~(^|\.)seznam\.cz$~'                             => array( 'Seznam', self::SEARCH ),
			'~(^|\.)naver\.com$~'                             => array( 'Naver', self::SEARCH ),
			'~(^|\.)(ask|aol)\.com$~'                         => array( 'Other search', self::SEARCH ),
			// Social networks and communities.
			'~(^|\.)(linkedin\.com|lnkd\.in)$|^com\.linkedin\.android$~' => array( 'LinkedIn', self::SOCIAL ),
			'~(^|\.)(facebook\.com|fb\.com|fb\.me)$|^com\.facebook\.~' => array( 'Facebook', self::SOCIAL ),
			'~(^|\.)instagram\.com$~'                         => array( 'Instagram', self::SOCIAL ),
			'~^(t\.co|twitter\.com|x\.com|mobile\.twitter\.com)$~' => array( 'X (Twitter)', self::SOCIAL ),
			'~(^|\.)reddit\.com$~'                            => array( 'Reddit', self::SOCIAL ),
			'~(^|\.)(youtube\.com|youtu\.be)$~'               => array( 'YouTube', self::SOCIAL ),
			'~^news\.ycombinator\.com$~'                      => array( 'Hacker News', self::SOCIAL ),
			'~(^|\.)xing\.com$~'                              => array( 'XING', self::SOCIAL ),
			'~(^|\.)bsky\.app$~'                              => array( 'Bluesky', self::SOCIAL ),
			'~(^|\.)threads\.(net|com)$~'                     => array( 'Threads', self::SOCIAL ),
			'~(^|\.)mastodon\.[a-z]+$|^mstdn\.~'              => array( 'Mastodon', self::SOCIAL ),
			'~(^|\.)pinterest\.[a-z.]+$~'                     => array( 'Pinterest', self::SOCIAL ),
			'~(^|\.)tiktok\.com$~'                            => array( 'TikTok', self::SOCIAL ),
			'~(^|\.)medium\.com$~'                            => array( 'Medium', self::SOCIAL ),
			'~(^|\.)dev\.to$~'                                => array( 'DEV Community', self::SOCIAL ),
			'~(^|\.)(slack\.com)$|^com\.slack$~'              => array( 'Slack', self::SOCIAL ),
			'~^teams\.(microsoft|live)\.com$|^teams\.cloud\.microsoft$~' => array( 'Microsoft Teams', self::SOCIAL ),
			// Notable referrers for an IT audience.
			'~(^|\.)github\.com$~'                            => array( 'GitHub', self::REFERRAL ),
			'~(^|\.)stackoverflow\.com$~'                     => array( 'Stack Overflow', self::REFERRAL ),
			'~(^|\.)clutch\.co$~'                             => array( 'Clutch', self::REFERRAL ),
		);
	}

	/**
	 * Common utm_source spellings => display name and organic channel.
	 *
	 * @return array
	 */
	private static function source_aliases() {
		return array(
			'google'     => array( 'Google', self::SEARCH ),
			'bing'       => array( 'Bing', self::SEARCH ),
			'linkedin'   => array( 'LinkedIn', self::SOCIAL ),
			'li'         => array( 'LinkedIn', self::SOCIAL ),
			'facebook'   => array( 'Facebook', self::SOCIAL ),
			'fb'         => array( 'Facebook', self::SOCIAL ),
			'meta'       => array( 'Facebook', self::SOCIAL ),
			'instagram'  => array( 'Instagram', self::SOCIAL ),
			'ig'         => array( 'Instagram', self::SOCIAL ),
			'twitter'    => array( 'X (Twitter)', self::SOCIAL ),
			'x'          => array( 'X (Twitter)', self::SOCIAL ),
			'youtube'    => array( 'YouTube', self::SOCIAL ),
			'reddit'     => array( 'Reddit', self::SOCIAL ),
			'xing'       => array( 'XING', self::SOCIAL ),
			'chatgpt'    => array( 'ChatGPT', self::AI ),
			'chatgpt.com' => array( 'ChatGPT', self::AI ),
			'perplexity' => array( 'Perplexity', self::AI ),
			'newsletter' => array( 'Newsletter', self::EMAIL ),
			'mailchimp'  => array( 'Mailchimp', self::EMAIL ),
			'hubspot'    => array( 'HubSpot', self::EMAIL ),
		);
	}

	/**
	 * Attribute a session.
	 *
	 * @param string $referrer  document.referrer.
	 * @param array  $params    Query parameters of the landing URL.
	 * @param string $site_host This site's host.
	 * @return array{channel:string,source:string,referrer_host:string,referrer_url:string,utm_source:string,utm_medium:string,utm_campaign:string,utm_term:string,utm_content:string}
	 */
	public static function classify( $referrer, array $params, $site_host ) {
		$out = array(
			'channel'       => self::DIRECT,
			'source'        => self::DIRECT,
			'referrer_host' => '',
			'referrer_url'  => '',
		);
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $key ) {
			$value                 = isset( $params[ 'utm_' . $key ] ) ? $params[ 'utm_' . $key ] : '';
			$out[ 'utm_' . $key ] = mb_substr( trim( sanitize_text_field( $value ) ), 0, 'source' === $key || 'medium' === $key ? 100 : 150 );
		}
		$out['utm_source'] = strtolower( $out['utm_source'] );
		$out['utm_medium'] = strtolower( $out['utm_medium'] );
		// Plausible / Matomo style ?ref= and ?source=.
		if ( '' === $out['utm_source'] ) {
			foreach ( array( 'ref', 'source', 'mtm_source', 'pk_source' ) as $alt ) {
				if ( ! empty( $params[ $alt ] ) ) {
					$out['utm_source'] = strtolower( mb_substr( sanitize_text_field( $params[ $alt ] ), 0, 100 ) );
					break;
				}
			}
		}

		// Referrer host and path.
		$known = null;
		$host  = self::host_of( $referrer );
		$site  = self::strip_www( strtolower( (string) $site_host ) );
		if ( '' !== $host && $host !== $site ) {
			$path                 = (string) wp_parse_url( $referrer, PHP_URL_PATH );
			$out['referrer_host'] = mb_substr( $host, 0, 191 );
			$out['referrer_url']  = mb_substr( $host . ( '/' === $path ? '' : $path ), 0, 255 );
			$known                = self::match_host( $host );
		}

		// Ad click identifiers win: they are only added by ad platforms.
		$click = array(
			'gclid'    => array( 'Google Ads', self::PAID_SEARCH ),
			'gbraid'   => array( 'Google Ads', self::PAID_SEARCH ),
			'wbraid'   => array( 'Google Ads', self::PAID_SEARCH ),
			'dclid'    => array( 'Google Display', self::DISPLAY ),
			'msclkid'  => array( 'Microsoft Ads', self::PAID_SEARCH ),
			'li_fat_id' => array( 'LinkedIn Ads', self::PAID_SOCIAL ),
			'twclid'   => array( 'X Ads', self::PAID_SOCIAL ),
			'ttclid'   => array( 'TikTok Ads', self::PAID_SOCIAL ),
		);
		foreach ( $click as $param => $info ) {
			if ( ! empty( $params[ $param ] ) ) {
				$out['source']  = $info[0];
				$out['channel'] = $info[1];
				return $out;
			}
		}

		if ( '' !== $out['utm_source'] || '' !== $out['utm_medium'] ) {
			$alias = self::match_source( $out['utm_source'] );
			if ( ! $alias && $known ) {
				$alias = $known;
			}
			$out['source']  = $alias ? $alias[0] : ( '' !== $out['utm_source'] ? $out['utm_source'] : ( $out['referrer_host'] ? $out['referrer_host'] : self::DIRECT ) );
			$out['channel'] = self::channel_for_medium( $out['utm_medium'], $alias ? $alias[1] : null );
			return $out;
		}

		if ( $known ) {
			$out['source']  = $known[0];
			$out['channel'] = $known[1];
		} elseif ( '' !== $out['referrer_host'] ) {
			$out['source']  = $out['referrer_host'];
			$out['channel'] = self::REFERRAL;
		}
		return $out;
	}

	/**
	 * Channel for a utm_medium value.
	 *
	 * @param string      $medium  utm_medium (lowercase).
	 * @param string|null $organic Channel the source would have organically.
	 * @return string
	 */
	private static function channel_for_medium( $medium, $organic ) {
		$is_social = self::SOCIAL === $organic;
		if ( preg_match( '/^(display|banner|cpm|expandable|interstitial|programmatic)$/', $medium ) ) {
			return self::DISPLAY;
		}
		if ( preg_match( '/^(.*cp.*|ppc|paid.*|sem|retargeting|sponsored)$/', $medium ) ) {
			return $is_social ? self::PAID_SOCIAL : self::PAID_SEARCH;
		}
		if ( preg_match( '/^(e[-_ ]?mail|newsletter|mail)$/', $medium ) ) {
			return self::EMAIL;
		}
		if ( preg_match( '/^(social|social[-_ ]?(network|media)|sm|organic[-_ ]?social)$/', $medium ) ) {
			return self::SOCIAL;
		}
		if ( preg_match( '/^(affiliate|affiliates|partner|partners)$/', $medium ) ) {
			return self::AFFILIATES;
		}
		if ( 'referral' === $medium ) {
			return self::REFERRAL;
		}
		if ( 'organic' === $medium ) {
			return self::SEARCH;
		}
		return $organic ? $organic : self::CAMPAIGNS;
	}

	/**
	 * Match a referrer host against known hosts.
	 *
	 * @param string $host Normalised host.
	 * @return array|null [name, channel]
	 */
	private static function match_host( $host ) {
		foreach ( self::known_hosts() as $regex => $info ) {
			if ( preg_match( $regex, $host ) ) {
				return $info;
			}
		}
		return null;
	}

	/**
	 * Match a utm_source value.
	 *
	 * @param string $source utm_source.
	 * @return array|null [name, channel]
	 */
	private static function match_source( $source ) {
		if ( '' === $source ) {
			return null;
		}
		$aliases = self::source_aliases();
		if ( isset( $aliases[ $source ] ) ) {
			return $aliases[ $source ];
		}
		return false !== strpos( $source, '.' ) ? self::match_host( self::strip_www( $source ) ) : null;
	}

	/**
	 * Host of a referrer URL, including android-app:// package referrers.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host_of( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host ? self::strip_www( strtolower( $host ) ) : '';
	}

	/**
	 * Remove a leading www. / m.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function strip_www( $host ) {
		return (string) preg_replace( '/^(www\d?|m)\./', '', $host );
	}
}
