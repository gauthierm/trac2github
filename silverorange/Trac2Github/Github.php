<?php

namespace silverorange\Trac2Github;

require_once 'Console/CommandLine.php';
//require_once 'HTTP/Request2.php';
require_once 'silverorange/Trac2Github/Github/Exception.php';

/**
 * @package   trac2github
 * @version   2.0
 * @author    Vladimir Sibirov
 * @author    Michael Gauthier <mike@silverorange.com>
 * @author    Lukas Eder
 * @copyright 2011 Vladimir Sibirov, 2013 silverorange
 * @license   BSD http://opensource.org/licenses/BSD-2-Clause
 */
class Github
{
	protected $config = array();
	protected $cli = null;

	public function __construct(\stdClass $config,
		\Console_CommandLine_Result $cli)
	{
		$this->config = $config;
		$this->cli = $cli;
	}

	public function post($url, $json, $patch = false)
	{
		$ua = sprintf(
			'trac2github for %s',
			$this->config->github->project
		);

		$auth = sprintf(
			'%s:%s',
			$this->config->github->username,
			$this->config->github->password
		);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_USERPWD, $auth);
		curl_setopt($ch, CURLOPT_URL, "https://api.github.com$url");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
		curl_setopt($ch, CURLOPT_USERAGENT, $ua);
		if ($patch) {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
		}
		$ret = curl_exec($ch);
		if (!$ret) {
			curl_close($ch);
			throw new Github_Exception(curl_error($ch));
		}
		return $ret;
	}

	public function addMilestone($data)
	{
		if ($this->cli->options['verbose']) {
			print_r($data);
		}

		$endpoint = sprintf(
			'/repos/%s/%s/milestones',
			$this->config->github->project,
			$this->config->github->repo
		);

		return json_decode(
			$this->post($endpoint, json_encode($data)),
			true
		);
	}

	public function addLabel($data)
	{
		if ($this->cli->options['verbose']) {
			print_r($data);
		}

		$endpoint = sprintf(
			'/repos/%s/%s/labels',
			$this->config->github->project,
			$this->config->github->repo
		);

		return json_decode(
			$this->post($endpoint, json_encode($data)),
			true
		);
	}

	public function addIssue($data)
	{
		if ($this->cli->options['verbose']) {
			print_r($data);
		}

		$endpoint = sprintf(
			'/repos/%s/%s/issues',
			$this->config->github->project,
			$this->config->github->repo
		);

		return json_decode(
			$this->post($endpoint, json_encode($data)),
			true
		);
	}

	public function addComment($issue, $body)
	{
		if ($this->cli->options['verbose']) {
			print_r($body);
		}

		$endpoint = sprintf(
			'/repos/%s/%s/issues/%s/comments',
			$this->config->github->project,
			$this->config->github->repo,
			$issue
		);

		return json_decode(
			$this->post($endpoint, json_encode(array('body' => $body))),
			true
		);
	}

	public function updateIssue($issue, $data)
	{
		if ($this->cli->options['verbose']) {
			print_r($data);
		}

		$endpoint = sprintf(
			'/repos/%s/%s/issues/%s',
			$this->config->github->project,
			$this->config->github->repo,
			$issue
		);

		return json_decode(
			$this->post($endpoint, json_encode($data), true),
			true
		);
	}
}
