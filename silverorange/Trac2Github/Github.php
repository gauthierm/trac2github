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

		$url = 'https://api.github.com' . $url;

		$ch = curl_init($url);

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_USERPWD        => $auth,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_POSTFIELDS     => $json,
				CURLOPT_USERAGENT      => $ua,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json'
				),
			)
		);

		if ($patch) {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
		} else {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		}

		$ret = curl_exec($ch);

		if ($ret === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new Github_Exception($error);
		}

		curl_close($ch);

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
