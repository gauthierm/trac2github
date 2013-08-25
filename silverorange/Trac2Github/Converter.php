<?php

namespace silverorange\Trac2Github;

require_once 'Console/CommandLine.php';
require_once 'silverorange/Trac2Github/Github.php';
require_once 'silverorange/Trac2Github/MoinMoin2Markdown.php';

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

	protected $config = null;
	protected $parser = null;
	protected $db = null;
	protected $github = null;

	public function __invoke()
	{
		$parser = \Console_CommandLine::fromXmlFile(
			__DIR__ . '/../../data/cli.xml'
		);
		$this->cli = $parser->parse();
		$this->config = $this->parseConfig($this->cli->options['config']);

		try {
			$db = new \PDO($this->config->trac->database->dsn);
		} catch (\PDOException $e) {
			$this->terminate(
				sprintf(
					'Unable to connect to database "%s"' . PHP_EOL,
					$this->config->trac->database->dsn
				)
			);
		}

		$this->github = new GitHub($this->config, $this->cli);
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

	protected function convertMilestones()
	{
		$milestones = null;

		if (is_readable($this->config->cache->milestones)) {
			$milestones = json_decode(
				file_get_contents($this->config->cache->milestones)
			);
		}

		if ($milestones === null) {
			$milestones = array();
			$res = $this->db->query('select * from milestone order by due');
			foreach ($res->fetchAll(PDO::FETCH_OBJ) as $row) {
				$resp = $this->github->addMilestone(
					array(
						'title'       => $row->name,
						'state'       => ($row->completed == 0) ? 'open' : 'closed',
						'description' => $this->getMilestoneDescription($row),
						'due_on'      => date('Y-m-d\TH:i:s\Z', (int)$row->due)
					)
				);

				if (isset($resp['number'])) {
					// OK
					$milestones[sha1($row->name)] = (int)$resp['number'];
					echo "Milestone {$row->name} converted to {$resp['number']}\n";
				} else {
					// Error
					$error = print_r($resp, 1);
					echo "Failed to convert milestone {$row->name}: $error\n";
				}
			}

			if ($this->config->cache->milestones != '') {
				file_put_contents(
					$this->config->cache->milestones,
					json_encode($milestones)
				);
			}
		}

		return $milestones;
	}

	protected function getMilestoneDescription($milestone)
	{
		$description = 'none';

		if ($milestone->description != '') {
			$description = MoinMoin2Markdown::convert($milestone->description);
		}

		return $description;
	}

	protected function convertLabels()
	{
		$labels = null;

		if (is_readable($this->config->cache->labels)) {
			$labels = json_decode(
				file_get_contents(
					$this->config->cache->labels
				)
			);
		}

		if ($labels === null) {
			$labels = array(
				'types'       => array(),
				'priorities'  => array(),
				'resolutions' => array(),
			);

			$res = $this->db->query(
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

				if (   isset($this->config->trac->{$row->label_type})
					&& isset($this->config->trac->{$row->label_type}->{$row->name})
				) {
					$label_config =
						$this->config->trac->{$row->label_type}->{$row->name};

					if (!isset($label_config->import)) {
						$label_config->import = false;
					}

					if (!isset($label_config->color)) {
						$label_config->color = 'ffffff';
					}
				}

				if ($label_config !== null && $label_config->import === true) {
					$resp = $this->github->addLabel(
						array(
							'name'  => $row->name,
							'color' => $color
						)
					);

					if (isset($resp['url'])) {
						// OK
						$labels[$row->label_type][sha1($row->name)] = $resp['name'];
						echo "Label {$row->name} converted to {$resp['name']}\n";
					} else {
						// Error
						$error = print_r($resp, 1);
						echo "Failed to convert label {$row->name}: $error\n";
					}
				}
			}

			if ($this->config->cache->labels != '') {
				file_put_contents(
					$this->config->cache->labels,
					json_encode($labels)
				);
			}
		}

		return $labels;
	}

	protected function convertTickets(
		array $milestones,
		array $labels
	) {
		$tickets = null;

		if (is_readable($config->cache->tickets)) {
			$tickets = json_decode(
				file_get_contents(
					$this->config->cache->tickets
				)
			);
		}

		if ($tickets === null) {
			$sql = 'select * from ticket order by id';

			if ($this->cli->options['ticket_limit'] > 0) {
				$sql .= sprintf(
					' limit %s',
					$this->db->quote(
						$this->cli->options['ticket_limit'],
						PDO::PARAM_INT
					)
				);
			}

			if ($this->cli->options['ticket_offset'] > 0) {
				$sql .= sprintf(
					' offset %s',
					$this->db->quote(
						$this->cli->options['ticket_offset'],
						PDO::PARAM_INT
					)
				);
			}

			$res = $this->db->query($sql);

			foreach ($res->fetchAll(PDO::FETCH_OBJ) as $row) {

				$ticket_labels = array();

				$type = sha1($row->type);
				if (!empty($labels['types'][$type])) {
					$ticket_labels[] = $labels['types'][$type];
				}

				$priority = sha1($row->priority);
				if (!empty($labels['priorities'][$priority])) {
					$ticket_labels[] = $labels['priorities'][$priority];
				}

				$resolution = sha1($row->resolution);
				if (!empty($labels['resolutions'][$resolution])) {
					$ticket_labels[] = $labels['resolutions'][$resolution];
				}

				$resp = $this->github->addIssue(
					array(
						'title'     => $row->summary,
						'body'      => $this->getIssueBody($row),
						'assignee'  => $this->getIssueAssignee($row),
						'milestone' => $milestones[sha1($row->milestone)],
						'labels'    => $ticket_labels,
					)
				);

				if (isset($resp['number'])) {
					// OK
					$tickets[$row->id] = (int)$resp['number'];
					echo "Ticket #{$row->id} converted to issue #{$resp['number']}\n";
					if ($row->status === 'closed') {
						// Close the issue
						$resp = $this->github->updateIssue(
							$resp['number'],
							array(
								'state' => 'closed'
							)
						);
						if (isset($resp['number'])) {
							echo "Closed issue #{$resp['number']}\n";
						}
					}

				} else {
					// Error
					$error = print_r($resp, 1);
					echo "Failed to convert a ticket #{$row->id}: $error\n";
				}
			}

			if ($this->config->cache->tickets != '') {
				file_put_contents(
					$this->config->cache->tickets,
					json_encode($tickets)
				);
			}
		}

		return $tickets;
	}

	protected function getIssueBody(\stdClass $ticket)
	{
		$body = 'none';

		if (!empty($row->description)) {
			$body = MoinMoin2Markdown::convert($ticket->description);
		}

		return $body
	}

	protected function getIssueAssignee(\stdClass $ticket)
	{
		// set default user for tickets with no user or tickets with
		// users that are not to be imported
		$assignee = $this->config->github->username;

		if (isset($this->config->trac->users->{$ticket->owner})) {
			$assignee = $this->config->trac->users->{$ticket->owner};
		}

		return $assignee;
	}

	protected function convertComments(
	) {
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