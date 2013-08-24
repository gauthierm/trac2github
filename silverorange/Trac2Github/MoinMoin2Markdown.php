<?php

namespace silverorange\Trac2Github;

class MoinMoin2Markdown
{
	public static function convert($data)
	{
		// Replace code blocks with an associated language
		$data = preg_replace('/\{\{\{(\s*#!(\w+))?/m', '```$2', $data);
		$data = preg_replace('/\}\}\}/', '```', $data);

		// Possibly translate other markup as well?
		return $data;
	}
}
