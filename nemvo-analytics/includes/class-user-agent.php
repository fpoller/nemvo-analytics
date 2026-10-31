<?php
/**
 * Lightweight user agent classification.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a user agent into device type, OS and browser family. Versions are
 * deliberately not kept: they add fingerprinting detail and rarely matter
 * for marketing decisions.
 */
class User_Agent {

	/**
	 * Whether the agent is a crawler, monitor, preview fetcher or script.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		if ( '' === trim( $ua ) || strlen( $ua ) < 20 ) {
			return true;
		}
		return (bool) preg_match(
			'~bot\b|bot/|crawl|spider|slurp|archiver|facebookexternalhit|facebookcatalog|embedly|quora link|outbrain|pinterest/|vkshare|w3c_validator|whatsapp|telegram|skypeuripreview|slackbot|discordbot|headless|phantomjs|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|pingdom|uptime|statuscake|site24x7|monitor|datadog|newrelic|checkly|curl/|wget/|python-|python/|httpclient|okhttp|go-http|java/|axios/|node-fetch|undici|libwww|scrapy|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-web|anthropic-ai|perplexitybot|ccbot|amazonbot|applebot|yandex(bot|images|metrika)|baiduspider|duckduckbot|bingpreview|google-inspectiontool|googleother|feedfetcher|rss|validator|preview|prerender~i',
			$ua
		);
	}

	/**
	 * A readable name for a crawler, tool or automated browser.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function bot_name( $ua ) {
		if ( '' === trim( $ua ) ) {
			return 'No user agent';
		}
		$names = array(
			'Googlebot', 'Google-InspectionTool', 'GoogleOther', 'AdsBot-Google', 'bingbot', 'BingPreview', 'Applebot', 'DuckDuckBot', 'YandexBot', 'Baiduspider',
			'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot', 'Bytespider', 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web',
			'PerplexityBot', 'CCBot', 'Amazonbot', 'facebookexternalhit', 'LinkedInBot', 'Twitterbot', 'Slackbot', 'Discordbot', 'TelegramBot', 'WhatsApp',
			'HeadlessChrome', 'Lighthouse', 'Chrome-Lighthouse', 'PageSpeed', 'GTmetrix', 'Pingdom', 'UptimeRobot', 'StatusCake', 'Site24x7', 'PhantomJS',
			'Puppeteer', 'Playwright', 'Selenium', 'Electron', 'curl', 'Wget', 'python-requests', 'Python', 'Go-http-client', 'Java', 'okhttp', 'axios',
			'node-fetch', 'undici', 'Scrapy', 'libwww-perl',
		);
		foreach ( $names as $name ) {
			if ( false !== stripos( $ua, $name ) ) {
				return 'bingbot' === $name ? 'Bingbot' : $name;
			}
		}
		return preg_match( '~([A-Za-z][\w-]*(?:bot|spider|crawler))~i', $ua, $m ) ? mb_substr( $m[1], 0, 40 ) : 'Other bot';
	}

	/**
	 * Parse a user agent.
	 *
	 * @param string $ua      User agent.
	 * @param bool   $touch   Client reported a touch screen (iPadOS detection).
	 * @return array{device:string,os:string,browser:string}
	 */
	public static function parse( $ua, $touch = false ) {
		$os = self::os( $ua, $touch );
		return array(
			'os'      => $os,
			'browser' => self::browser( $ua ),
			'device'  => self::device( $ua, $os ),
		);
	}

	/**
	 * Operating system family.
	 *
	 * @param string $ua    User agent.
	 * @param bool   $touch Touch screen reported by the client.
	 * @return string
	 */
	private static function os( $ua, $touch ) {
		$rules = array(
			'~Windows Phone~i'          => 'Windows Phone',
			'~Windows~i'                => 'Windows',
			'~iPad~'                    => 'iPadOS',
			'~iPhone|iPod~'             => 'iOS',
			'~CrOS~'                    => 'ChromeOS',
			'~HarmonyOS~i'              => 'HarmonyOS',
			'~Android~i'                => 'Android',
			'~Macintosh|Mac OS X~'      => 'macOS',
			'~Ubuntu|Fedora|Linux|X11~' => 'Linux',
			'~FreeBSD|OpenBSD~'         => 'BSD',
		);
		foreach ( $rules as $regex => $name ) {
			if ( preg_match( $regex, $ua ) ) {
				// iPadOS 13+ reports itself as a Mac; the touch screen gives it away.
				if ( 'macOS' === $name && $touch ) {
					return 'iPadOS';
				}
				return $name;
			}
		}
		return 'Other';
	}

	/**
	 * Browser family.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	private static function browser( $ua ) {
		$rules = array(
			'~LinkedInApp~'                 => 'LinkedIn app',
			'~FBAN|FBAV|FB_IAB~'            => 'Facebook app',
			'~Instagram~'                   => 'Instagram app',
			'~Teams/|MicrosoftTeams~'       => 'Teams',
			'~Edg(e|A|iOS)?/~'              => 'Edge',
			'~OPR/|Opera~'                  => 'Opera',
			'~SamsungBrowser~'              => 'Samsung Internet',
			'~YaBrowser~'                   => 'Yandex Browser',
			'~Vivaldi~'                     => 'Vivaldi',
			'~DuckDuckGo/|Ddg/~'            => 'DuckDuckGo',
			'~UCBrowser~'                   => 'UC Browser',
			'~Firefox/|FxiOS~'              => 'Firefox',
			'~MSIE |Trident/~'              => 'Internet Explorer',
			'~Chrome/|CriOS|Chromium~'      => 'Chrome',
			'~Version/[\d.]+.*Safari/~'     => 'Safari',
			'~AppleWebKit.*Mobile/~'        => 'Safari (in-app)',
			'~Safari/~'                     => 'Safari',
		);
		foreach ( $rules as $regex => $name ) {
			if ( preg_match( $regex, $ua ) ) {
				return $name;
			}
		}
		return 'Other';
	}

	/**
	 * Device class.
	 *
	 * @param string $ua User agent.
	 * @param string $os Parsed OS.
	 * @return string desktop|mobile|tablet
	 */
	private static function device( $ua, $os ) {
		if ( 'iPadOS' === $os || preg_match( '~Tablet|Kindle|Silk/|PlayBook|Nexus (7|9|10)|SM-T\d~i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '~Mobi|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini~i', $ua ) ) {
			return 'mobile';
		}
		if ( 'Android' === $os || 'HarmonyOS' === $os ) {
			// Android without "Mobile" is a tablet by Google's convention.
			return 'tablet';
		}
		return 'desktop';
	}

	/**
	 * Bucket a screen width so the exact resolution is never stored.
	 *
	 * @param int $width CSS pixel width.
	 * @return string
	 */
	public static function screen_class( $width ) {
		$width = (int) $width;
		if ( $width <= 0 ) {
			return '';
		}
		if ( $width < 576 ) {
			return 'xs';
		}
		if ( $width < 768 ) {
			return 'sm';
		}
		if ( $width < 1024 ) {
			return 'md';
		}
		if ( $width < 1440 ) {
			return 'lg';
		}
		if ( $width < 1920 ) {
			return 'xl';
		}
		return 'xxl';
	}
}
