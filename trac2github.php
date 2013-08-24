#!/usr/bin/env php
<?php

namespace silverorange\Trac2Github;

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

require_once 'Console/CommandLine.php';
//require_once 'HTTP/Request2.php';

/**
 * @package   trac2github
 * @version   2.0
 * @author    Vladimir Sibirov
 * @author    Michael Gauthier <mike@silverorange.com>
 * @author    Lukas Eder
 * @copyright 2011 Vladimir Sibirov, 2013 silverorange
 * @license   BSD http://opensource.org/licenses/BSD-2-Clause
 */
class Converter
{
	protected static $default_config = <<<JAVASCRIPT
{
	"cache": {
		"milestones": "/tmp/trac_milestones.json",
		"labels": "/tmp/trac_labels.json",
		"tickets": "/tmp/trac_tickets.json"
	},
	"github": {
		"username": "",
		"password": "",
		"project": "",
		"repo": ""
	},
	"trac": {
		"database": {
			"dsn": ""
		},
		"users": {},
		"priorities": {},
		"types": {},
		"resolutions": {}
	}
}
JAVASCRIPT;

	public function __invoke()
	{
		$parser = \Console_CommandLine::fromXmlFile(__DIR__ . '/cli.xml');
		$result = $parser->parse();
		$config = $this->parseConfig($result->options['config']);

		try {
			$db = new \PDO($config->trac->database->dsn);
		} catch (\PDOException $e) {
			$this->terminate(
				sprintf(
					'Unable to connect to database "%s"' . PHP_EOL,
					$config->trac->database->dsn
				)
			);
		}

		$github = new GitHub($config, $result);
	}

	protected function terminate($message)
	{
		fwrite(STDERR, $message);
		fflush(STDERR);
		exit(1);
	}

	protected function parseConfig($filename)
	{
		if (!is_readable($filename)) {
			$this->terminate(
				sprintf(
					'Could not open config file "%s" for reading.' . PHP_EOL,
					$filename
				)
			);
		}

		$default_config = json_decode(self::$default_config);
		$config = json_decode(file_get_contents($filename));

		if ($config === null) {
			$this->terminate(
				sprintf(
					'Config file "%s" is not properly formatted JOSN.'
					. PHP_EOL,
					$filename
				)
			);
		}

		$merge = function(\stdClass $config1, \stdClass $config2) use (&$merge)
		{
			$merged = $config1;

			foreach (get_object_vars($config2) as $key => $value) {
				if (is_object($value) && isset($merged->$key) && is_object($merged->$key)) {
					$merged->$key = $merge($merged->$key, $value);
				} else {
					$merged->$key = $value;
				}
			}

			return $merged;
		};

		return $merge($default_config, $config);
	}

	protected function convertMilestones(array $config, \PDO $db,
		Github $github)
	{
		$milestones = null;

		if (is_readable($config->cache->milestones)) {
			$milestones = json_decode(
				file_get_contents($config->cache->milestones)
			);
		}

		if ($milestones === null) {
			$milestones = array();
			$res = $db->query('select * from milestone order by due');
			foreach ($res->fetchAll() as $row) {
				$resp = $github->addMilestone(
					array(
						'title'       => $row['name'],
						'state'       => ($row['completed'] == 0) ? 'open' : 'closed',
						'description' => (empty($row['description'])) ? 'None' : $row['description'],
						'due_on'      => date('Y-m-d\TH:i:s\Z', (int)$row['due'])
					)
				);

				if (isset($resp['number'])) {
					// OK
					$milestones[sha1($row['name'])] = (int)$resp['number'];
					echo "Milestone {$row['name']} converted to {$resp['number']}\n";
				} else {
					// Error
					$error = print_r($resp, 1);
					echo "Failed to convert milestone {$row['name']}: $error\n";
				}
			}

			if ($config->cache->milestones != '') {
				file_put_contents(
					$config->cache->milestones,
					json_encode($milestones)
				);
			}
		}

		return $milestones;
	}

	protected function convertLabels(array $config, \PDO $db,
		Github $github)
	{
		$labels = null;

		if (is_readable($config->cache->labels)) {
			$labels = json_decode(file_get_contents($config->cache->labels));
		}

		if ($labels === null) {
			$labels = array(
				'types'       => array(),
				'priorities'  => array(),
				'resolutions' => array(),
			);

			$res = $db->query(
				'select distinct \'types\' label_type, lower(type) name
				from ticket where type is not null and type != \'\'
				union
				select distinct \'priorities\' label_type, lower(priority) name
				from ticket where priority is not null and priority != \'\'
				union
				select distinct \'resolutions\' label_type, lower(resolution) name
				from ticket where resolution is not null and resolution != \'\''
			);

			foreach ($res->fetchAll(PDO::FETCH_OBJ) as $row) {
				$label_config = null;

				if (   isset($config->trac->{$row->label_type})
					&& isset($config->trac->{$row->label_type}->{$row->name})
				) {
					$label_config =
						$config->trac->{$row->label_type}->{$row->name};

					if (!isset($label_config->import)) {
						$label_config->import = false;
					}

					if (!isset($label_config->color)) {
						$label_config->color = 'ffffff';
					}
				}

				if ($label_config !== null && $label_config->import === true) {
					$resp = $github->addLabel(
						array(
							'name'  => $row->name,
							'color' => $color
						)
					);

					if (isset($resp['url'])) {
						// OK
						$labels[$row->label_type][sha1($row->name)] = $resp['name'];
						echo "Label {$row['name']} converted to {$resp['name']}\n";
					} else {
						// Error
						$error = print_r($resp, 1);
						echo "Failed to convert label {$row['name']}: $error\n";
					}
				}
			}

			if ($config->cache->labels != '') {
				file_put_contents(
					$config->cache->labels,
					json_encode($labels)
				);
			}
		}
	}

	public function clearCache(\stdClass $config)
	{
		if (file_exists($config->cache->milestones)) {
			unlink($config->cache->milestones);
		}
		if (file_exists($config->cache->labels)) {
			unlink($config->cache->labels);
		}
		if (file_exists($config->cache->tickets)) {
			unlink($config->cache->tickets);
		}
	}
}

class GithubException extends \Exception
{
}

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
			throw new GithubException(curl_error($ch));
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

$converter = new Converter();
$converter();
exit();



// Try get previously fetched tickets
$tickets = array();
if (file_exists($save_tickets)) {
	$tickets = unserialize(file_get_contents($save_tickets));
}

if (!$skip_tickets) {
	// Export tickets
	$limit = $ticket_limit > 0 ? "LIMIT $ticket_offset, $ticket_limit" : '';
	$res = $trac_db->query("SELECT * FROM `ticket` ORDER BY `id` $limit");
	foreach ($res->fetchAll() as $row) {
		if (empty($row['milestone'])) {
			continue;
		}
		if (empty($row['owner']) || !isset($users_list[$row['owner']])) {
			$row['owner'] = $username;
		}
		$ticketLabels = array();
		if (!empty($labels['T'][crc32($row['type'])])) {
		    $ticketLabels[] = $labels['T'][crc32($row['type'])];
		}
		if (!empty($labels['C'][crc32($row['component'])])) {
		    $ticketLabels[] = $labels['C'][crc32($row['component'])];
		}
		if (!empty($labels['P'][crc32($row['priority'])])) {
		    $ticketLabels[] = $labels['P'][crc32($row['priority'])];
		}
		if (!empty($labels['R'][crc32($row['resolution'])])) {
		    $ticketLabels[] = $labels['R'][crc32($row['resolution'])];
		}

        // There is a strange issue with summaries containing percent signs...
		$resp = github_add_issue(array(
			'title' => preg_replace("/%/", '[pct]', $row['summary']),
			'body' => empty($row['description']) ? 'None' : translate_markup($row['description']),
			'assignee' => isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'],
			'milestone' => $milestones[crc32($row['milestone'])],
			'labels' => $ticketLabels
		));
		if (isset($resp['number'])) {
			// OK
			$tickets[$row['id']] = (int) $resp['number'];
			echo "Ticket #{$row['id']} converted to issue #{$resp['number']}\n";
			if ($row['status'] == 'closed') {
				// Close the issue
				$resp = github_update_issue($resp['number'], array(
					'title' => preg_replace("/%/", '[pct]', $row['summary']),
					'body' => empty($row['description']) ? 'None' : translate_markup($row['description']),
					'assignee' => isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'],
					'milestone' => $milestones[crc32($row['milestone'])],
					'labels' => $ticketLabels,
					'state' => 'closed'
				));
				if (isset($resp['number'])) {
					echo "Closed issue #{$resp['number']}\n";
				}
			}

		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert a ticket #{$row['id']}: $error\n";
		}
	}
	// Serialize to restore in future
	file_put_contents($save_tickets, serialize($tickets));
}

if (!$skip_comments) {
	// Export all comments
	$limit = $comments_limit > 0 ? "LIMIT $comments_offset, $comments_limit" : '';
	$res = $trac_db->query("SELECT * FROM `ticket_change` where `field` = 'comment' AND `newvalue` != '' ORDER BY `ticket`, `time` $limit");
	foreach ($res->fetchAll() as $row) {
		$text = strtolower($row['author']) == strtolower($username) ? $row['newvalue'] : '**Author: ' . $row['author'] . "**\n" . $row['newvalue'];
		$resp = github_add_comment($tickets[$row['ticket']], translate_markup($text));
		if (isset($resp['url'])) {
			// OK
			echo "Added comment {$resp['url']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to add a comment: $error\n";
		}
	}
}

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

?>
