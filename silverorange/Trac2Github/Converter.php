<?php

namespace silverorange\Trac2Github;

require_once 'Console/CommandLine.php';
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
	// {{{ $defaultConfig

	protected static $defaultConfig = <<<JAVASCRIPT
{
	"database": {
		"dsn": ""
	},
	"users": {},
	"priorities": {},
	"types": {},
	"resolutions": {}
}
JAVASCRIPT;

	// }}}

	protected $config = null;
	protected $cli = null;
	protected $db = null;

	public function __invoke()
	{
		try {
			$parser = \Console_CommandLine::fromXmlFile(
				__DIR__ . '/../../data/cli.xml'
			);
			$this->cli = $parser->parse();
		} catch (Console_CommandLineException $e) {
			$parser->displayError($e->getMessage());
			exit(1);
		}

		$this->config = $this->parseConfig($this->cli->options['config']);

		try {
			$this->db = new \PDO($this->config->database->dsn);
		} catch (\PDOException $e) {
			$this->terminate(
				sprintf(
					'Unable to connect to database "%s"' . PHP_EOL,
					$this->config->database->dsn
				)
			);
		}

		$milestones = $this->convertMilestones();
	}

	protected function terminate($message)
	{
		fwrite(STDERR, $message);
		fflush(STDERR);
		exit(1);
	}

	// {{{ parseConfig()

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

		$defaultConfig = json_decode(self::$defaultConfig);
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

		return $merge($defaultConfig, $config);
	}

	// }}}
	// {{{ convertMilestones()

	protected function convertMilestones()
	{
		if ($this->cli->options['verbose']) {
			echo 'Exporting milestones:' . PHP_EOL . PHP_EOL;
		}

		$milestones = array();

		$directory = $this->cli->options['dir'] . DIRECTORY_SEPARATOR
			. 'milestones';

		if (!file_exists($directory)) {
			mkdir($directory, 0770, true);
		}

		$statement = $this->db->prepare(
			'select milestone.*, min(ticket.time) as createdate
			from milestone
				left outer join ticket on ticket.milestone = milestone.name
			where ticket.component = :component
			group by milestone.name, milestone.due, milestone.completed,
				milestone.description
			order by milestone.due'
		);

		$statement->bindParam(
			':component',
			$this->cli->args['component'],
			\PDO::PARAM_STR
		);

		$statement->execute();

		$id = 1;
		foreach ($statement->fetchAll(\PDO::FETCH_OBJ) as $row) {
			$milestone = new \stdClass();

			$milestone->id   = $id;
			$milestone->data = $this->convertMilestone($id, $row);

			$milestones[$row->name] = $milestone;

			$id++;
		}

		if ($this->cli->options['verbose']) {
			echo PHP_EOL;
		}

		return $milestones;
	}

	// }}}
	// {{{ convertMilestone()

	protected function convertMilestone($id, \stdClass $row)
	{
		$milestone = new \stdClass();

		$milestone->title       = $row->name;
		$milestone->state       = ($row->completed == 0) ? 'open' : 'closed';
		$milestone->description = $this->getMilestoneDescription($row);
		$milestone->due_on      = date('Y-m-d\TH:i:s\Z', (int)$row->due);
		$milestone->created_at  = date('Y-m-d\TH:i:s\Z', (int)$row->createdate);

		if (version_compare('5.4.0', PHP_VERSION, 'le')) {
			$content = json_encode($milestone, JSON_PRETTY_PRINT);
		} else {
			$content = json_encode($milestone);
		}

		$filename = $this->cli->options['dir'] . DIRECTORY_SEPARATOR
			. 'milestones' . DIRECTORY_SEPARATOR . $id . '.json';

		file_put_contents($filename, $content);

		if ($this->cli->options['verbose']) {
			echo $content;
			echo PHP_EOL;
		}

		return $milestone;
	}

	// }}}
	// {{{ getMilestoneDescription()

	protected function getMilestoneDescription($milestone)
	{
		$description = 'none';

		if ($milestone->description != '') {
			$description = str_replace("\r\n", "\n", $milestone->description);
			$description = MoinMoin2Markdown::convert($description);
		}

		return $description;
	}

	// }}}

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

			foreach ($res->fetchAll(\PDO::FETCH_OBJ) as $row) {
				$config = null;

				if (   isset($this->config->trac->{$row->label_type})
					&& isset($this->config->trac->{$row->label_type}->{$row->name})
				) {
					$config = $this->config->trac->{$row->label_type}->{$row->name};
				}

				if ($config !== null) {
					$label = $this->convertLabel($config, $row->name);
					if ($label !== null) {
						$labels[$row->label_type][sha1($row->name)] = $label;
					}
				}
			}

			// import new priorities from config
			$priorities = get_object_vars($this->config->trac->priorities);
			foreach ($priorities as $name => $config) {
				if (!isset($labels['priorities'][sha1($name)])) {
					$label = $this->convertLabel($config, $name);
					if ($label !== null) {
						$labels['priorities'][sha1($row->name)] = $label;
					}
				}
			}

			// import new types from config
			$types = get_object_vars($this->config->trac->types);
			foreach ($types as $name => $config) {
				if (!isset($labels['types'][sha1($name)])) {
					$label = $this->convertLabel($config, $name);
					if ($label !== null) {
						$labels['types'][sha1($row->name)] = $label;
					}
				}
			}

			// import new resolutions from config
			$resolutions = get_object_vars($this->config->trac->resolutions);
			foreach ($resolutions as $name => $config) {
				if (!isset($labels['resolutions'][sha1($name)])) {
					$label = $this->convertLabel($config, $name);
					if ($label !== null) {
						$labels['resolutions'][sha1($row->name)] = $label;
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

	protected function convertLabel(\stdClass $config, $name)
	{
		$label = null;

		if (isset($config->import) && $config->import === true) {
			$resp = $this->github->addLabel(
				array(
					'name'  => $name,
					'color' => $this->getColor($config),
				)
			);

			if (isset($resp['url'])) {
				echo 'Label ' . $row->name . ' converted to '
					. $resp['name'] . PHP_EOL;

				$label = $resp['name'];
			} else {
				$error = print_r($resp, true);
				echo 'Failed to convert label ' . $row->name
					. ': ' . $error . PHP_EOL;
			}
		}

		return $label;
	}

	protected function getColor(\stdClass $config, $default = 'ffffff')
	{
		$color = $default;

		if (isset($config->color)) {
			$color = $config->color;
		}

		return $color;
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

				$resp = $this->github->addIssue(
					array(
						'title'     => $row->summary,
						'body'      => $this->getIssueBody($row),
						'assignee'  => $this->getIssueAssignee($row),
						'milestone' => $milestones[sha1($row->milestone)],
						'labels'    => $this->getIssueLabels($row),
					)
				);

				if (isset($resp['number'])) {
					$tickets[$row->id] = (int)$resp['number'];
					echo 'Ticket #' . $row->id . ' converted to issue '
						. '#' . $resp['number'] . PHP_EOL;

					if ($row->status === 'closed') {
						$resp = $this->github->updateIssue(
							$resp['number'],
							array(
								'state' => 'closed'
							)
						);
						if (isset($resp['number'])) {
							echo '=> closed issue # ' . $resp['number']
								. PHP_EOL;
						}
					}

				} else {
					$error = print_r($resp, 1);
					echo 'Failed to convert ticket #' . $row->id . ': '
						. $error . PHP_EOL;
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

	protected function getIssueLabels(\stdClass $ticket, array $labels)
	{
		$labels = array();

		if (isset($this->config->trac->types->{$ticket->type})) {
			$config = $this->config->trac->types->{$ticket->type};
			if (isset($config->import)) {
				if ($config->import === true) {
					$type = sha1($ticket->type);
				} else {
					$type = sha1($config->import);
				}

				if (isset($labels['types'][$type])) {
					$labels[] = $labels['types'][$type];
				}
			}
		}

		if (isset($this->config->trac->priorities->{$ticket->priority})) {
			$config = $this->config->trac->priorities->{$ticket->priority};
			if (isset($config->import)) {
				if ($config->import === true) {
					$priority = sha1($ticket->priority);
				} else {
					$priority = sha1($config->import);
				}

				if (isset($labels['priorities'][$priority])) {
					$labels[] = $labels['priorities'][$priority];
				}
			}
		}

		if (isset($this->config->trac->resolutions->{$ticket->resolution})) {
			$config = $this->config->trac->resolutions->{$ticket->resolution};
			if (isset($config->import)) {
				if ($config->import === true) {
					$resolution = sha1($ticket->resolution);
				} else {
					$resolution = sha1($config->import);
				}

				if (isset($labels['resolutions'][$resolution])) {
					$labels[] = $labels['resolutions'][$resolution];
				}
			}
		}

		return $labels;
	}

	protected function getIssueBody(\stdClass $ticket)
	{
		$body = sprintf('Trac Ticket #%s', $ticket->id);

		if (!empty($row->description)) {
			$body .= "\n\n";
			$body .= MoinMoin2Markdown::convert($ticket->description);
		}

		return $body;
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
		$comments = null;

		if ($comments === null) {
			$sql = 'select * from ticket_change '
				. 'where field = \'comment\' and newvalue != \'\' '
				. 'order by ticket, time';

			if ($this->cli->options['comment_limit'] > 0) {
				$sql .= sprintf(
					' limit %s',
					$this->db->quote(
						$this->cli->options['comment_limit'],
						PDO::PARAM_INT
					)
				);
			}

			if ($this->cli->options['comment_offset'] > 0) {
				$sql .= sprintf(
					' offset %s',
					$this->db->quote(
						$this->cli->options['comment_offset'],
						PDO::PARAM_INT
					)
				);
			}

			$res = $this->db->query($sql);

			foreach ($res->fetchAll(PDO::FETCH_OBJ) as $row) {
				$text = strtolower($row['author']) == strtolower($username) ? $row['newvalue'] : '**Author: ' . $row['author'] . "**\n" . $row['newvalue'];

				$resp = $this->github->addComment(
					$tickets[$row->ticket],
					translate_markup($text)
				);

				if (isset($resp['url'])) {
					echo "Added comment {$resp['url']}\n";
				} else {
					$error = print_r($resp, true);
					echo "Failed to add a comment: $error\n";
				}
			}
		}
	}
}
