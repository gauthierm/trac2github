<?php

namespace silverorange\Trac2Github;

class TracWikiToGFM
{
	public static $baseUri = 'https://www.github.com/';

	public static function convert($data)
	{
		$data = str_replace("\r\n", "\n", $data);
		$data = str_replace("\r",   "\n", $data);

		$codeBlocks = self::getCodeBlocks($data);

		$data = self::removeCodeBlocks($data);

		$data = self::convertHeaders($data);
		$data = self::convertBreaks($data);
		$data = self::convertRules($data);
		$data = self::convertMacros($data);
		$data = self::convertLinks($data);
		$data = self::convertEmAndStrong($data);
		$data = self::convertTables($data);
		$data = self::convertWikiLinks($data);
		$data = self::convertEscape($data);

		$data = self::replaceCodeBlocks($data, $codeBlocks);

		return $data;
	}

	public static function getCodeBlocks($data)
	{
		$blocks = array();

		$matches = array();
		preg_match_all(
			'/\{\{\{(?:\s*#!(\w+)\n)?(.*)\}\}\}/ms',
			$data,
			$matches,
			PREG_SET_ORDER
		);

		foreach ($matches as $set) {
			$blocks[] = array(
				'language' => $set[1],
				'code'     => ltrim($set[2], "\n"),
			);
		}

		return $blocks;
	}

	public static function removeCodeBlocks($data)
	{
		return preg_replace(
			'/\{\{\{(\s*#!\w+\n)?(.*)\}\}\}/ms',
			'%s',
			str_replace('%', '%%', $data)
		);
	}

	public static function replaceCodeBlocks($data, array $blocks)
	{
		$replacements = array();

		foreach ($blocks as $block) {
			if ($block['language'] == '') {
				$replacements[] = "```\n" . $block['code'] . "\n```";
			} else {
				$replacements[] = "```\n" . $block['language'] . "\n" .
					$block['code'] . "\n```";
			}
		}

		return vsprintf($data, $replacements);
	}

	public static function convertHeaders($data)
	{
		$matches = array();

		preg_match_all(
			'/^(=+)\s*(.*?)\s*=+\s*$/m',
			$data,
			$matches,
			PREG_SET_ORDER
		);

		$replace = function($matches)
		{
			$level = strlen($matches[1]);
			$title = $matches[2];

			switch ($level) {
			case 1:
				$header = $title . "\n" . str_repeat('=', strlen($title));
				break;
			case 2:
				$header = $title . "\n" . str_repeat('-', strlen($title));
				break;
			default:
				$header = str_repeat('#', $level) . ' ' . $title . "\n";
				break;
			}
			return $header;
		};

		return preg_replace_callback(
			'/^(=+)\s*(.*?)\s*=+\s*$/m',
			$replace,
			$data
		);
	}

	public static function convertBreaks($data)
	{
		// replace br at end of line
		$data = preg_replace('/\s*\[\[\s*br\s*\]\]\s*$/mi', '  ', $data);

		// replace inline br
		$data = preg_replace('/\s*\[\[\s*br\s*\]\]\s*/mi', "  \n", $data);

		return $data;
	}

	public static function convertRules($data)
	{
		// Replace hr on line by itself. Any trailing chars are ignored.
		return preg_replace('/^\s*-{4,}.*$/mi', '* * *', $data);
	}

	public static function convertEscape($data)
	{
		$data = preg_replace('/!\b/m', '', $data);
		return $data;
	}

	public static function convertMacros($data)
	{
		$data = preg_replace('/\[\[.*?\]\]\s*/', '', $data);
		return $data;
	}

	public static function convertLinks($data)
	{
		$replace = function($matches)
		{
			$parts = explode(' ', $matches[1]);
			if (count($parts) > 1) {
				$link = '[' . $parts[1] . '](' . $parts[0] . ')';
			} else {
				if (preg_match('/^wiki:/', $parts[0]) === 1) {
					$title = preg_replace('/^wiki:/', '', $parts[0]);
					$link = '[' . $title . '](' . $parts[0] . ')';
				} else {
					if (preg_match('/^[0-9]+$/', $parts[0]) === 1) {
						$link = 'SVN commit r' . $parts[0];
					} else {
						$link = $parts[0];
					}
				}
			}

			return $link;
		};

		return preg_replace_callback('/\[(.*?)\]/', $replace, $data);
	}

	public static function convertEmAndStrong($data)
	{
		// bold italic
		$data = preg_replace("/'''''(.*?)'''''/", '**_\1_**', $data);

		// bold
		$data = preg_replace("/'''(.*?)'''/", '**\1**', $data);

		// italic
		$data = preg_replace("/''(.*?)''/", '*\1*', $data);

		// italic
		$data = preg_replace("!//(.*?)//!", '*\1*', $data);

		return $data;
	}

	public static function convertTables($data)
	{
		$replace = function($matches)
		{
			$out = "<table>\n";

			$rows = explode("\n", trim($matches[0]));
			foreach ($rows as $row) {
				$cols = explode('||', trim($row, "\t\n |"));
				$cols = array_map(
					function($cell)
					{
						return "    <td>" . htmlspecialchars($cell) . "</td>\n";
					},
					$cols
				);

				$out .= "  <tr>\n" . implode('', $cols) . "  </tr>\n";
			}

			$out .= "</table>\n";

			return $out;
		};

		return preg_replace_callback(
			'/(?:^\s*\|\|.*?\n)+/ms',
			$replace,
			$data
		);
	}

	public static function convertWikiLinks($data)
	{
		$data = preg_replace_callback(
			'/(?<!!)((?:[A-Z][a-z]+){2,})/',
			function ($matches) {
				return '['. trim(
					preg_replace(
						'/[A-Z][a-z]+/',
						' \1',
						$matches[1]
					)
				) . '](' . TracWikiToGFM::$baseUri .
					'/wiki/' . $matches[1] . ')';
			},
			$data
		);
		return $data;
	}
}
