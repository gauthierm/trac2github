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
		$issues     = $this->convertIssues($milestones);
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
	// {{{ getDate()

	protected function getDate($timestamp)
	{
		return date('Y-m-d\TH:i:s\Z', (int)$timestamp);
	}

	// }}}
	// {{{ convertMilestones()

	protected function convertMilestones()
	{
		if ($this->cli->options['verbose']) {
			echo 'Exporting milestones:' . PHP_EOL . PHP_EOL;
		}

		$directory = $this->cli->options['dir'] . DIRECTORY_SEPARATOR
			. 'milestones';

		if (!file_exists($directory)) {
			mkdir($directory, 0770, true);
		}

		$milestones = array();

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
		$milestone->due_on      = $this->getDate($row->due);
		$milestone->created_at  = $this->getDate($row->createdate);

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
	// {{{ getColor()

	protected function getColor(\stdClass $config, $default = 'ffffff')
	{
		$color = $default;

		if (isset($config->color)) {
			$color = $config->color;
		}

		return $color;
	}

	// }}}
	// {{{ convertIssues()

	protected function convertIssues(
		array $milestones
	) {
		if ($this->cli->options['verbose']) {
			echo 'Exporting issues:' . PHP_EOL . PHP_EOL;
		}

		$directory = $this->cli->options['dir'] . DIRECTORY_SEPARATOR
			. 'issues';

		if (!file_exists($directory)) {
			mkdir($directory, 0770, true);
		}

		$issues = array();

		$statement = $this->db->prepare(
			'select * from ticket
			where component = :component
			order by id'
		);

		$statement->bindParam(
			':component',
			$this->cli->args['component'],
			\PDO::PARAM_STR
		);

		$statement->execute();

		$id = 1;
		foreach ($statement->fetchAll(\PDO::FETCH_OBJ) as $row) {
			$issues[$id] = $this->convertIssue($milestones, $id, $row);
			$id++;
		}

		return $issues;
	}

	// }}}
	// {{{ convertIssue()

	protected function convertIssue(array $milestones, $id, \stdClass $row)
	{
		$issue = new \stdClass();

		$issue->title      = $row->summary;
		$issue->body       = $this->getIssueBody($row);
		$issue->assignee   = $this->getIssueAsignee($row->owner);
		$issue->user       = $this->getUser($row->reporter);
		$issue->labels     = $this->getIssueLabels($row);
		$issue->created_at = $this->getDate($row->time);
		$issue->updated_at = $this->getDate($row->changetime);
		$issue->state      = ($row->status === 'closed') ? 'closed' : 'open';

		if ($row->status === 'closed') {
			$issue->closed_at = $this->getIssueClosedAt($row);
		}

		if ($row->milestone != '') {
			$issue->milestone = $milestones[$row->milestone]->id;
		}

		if (version_compare('5.4.0', PHP_VERSION, 'le')) {
			$content = json_encode($issue, JSON_PRETTY_PRINT);
		} else {
			$content = json_encode($issue);
		}

		$filename = $this->cli->options['dir'] . DIRECTORY_SEPARATOR
			. 'issues' . DIRECTORY_SEPARATOR . $id . '.json';

		file_put_contents($filename, $content);

		if ($this->cli->options['verbose']) {
			echo $content;
			echo PHP_EOL;
		}

		return $issue;
	}

	// }}}
	// {{{ getIssueClosedAt()

	protected function getIssueClosedAt(\stdClass $ticket)
	{
		static $statement = null;

		if ($statement === null) {
			$statement = $this->db->prepare(
				'select max(time) as closed_at
				from ticket_change
				where field = \'status\' and newvalue = \'closed\'
					and ticket = :ticket
				group by ticket'
			);
		}

		$statement->bindParam(':ticket', $ticket->id, \PDO::PARAM_INT);
		$statement->execute();

		$time = $statement->fetchColumn();

		return $this->getDate($time);
	}

	// }}}
	// {{{ getIssueLabels()

	protected function getIssueLabels(\stdClass $ticket)
	{
		$labels = array();

		if (isset($this->config->types->{$ticket->type})) {
			$config = $this->config->types->{$ticket->type};
			if (isset($config->import)) {
				$label = new \stdClass();

				if ($config->import === true) {
					$label->name = $ticket->type;
				} else {
					$label->name = $config->import;
				}

				if (isset($this->config->types->{$label->name})) {
					$label->color = $this->getColor(
						$this->config->types->{$label->name}
					);
				}

				$labels[] = $label;
			}
		}

		if (isset($this->config->priorities->{$ticket->priority})) {
			$config = $this->config->priorities->{$ticket->priority};
			if (isset($config->import)) {
				$label = new \stdClass();

				if ($config->import === true) {
					$label->name = $ticket->priority;
				} else {
					$label->name = $config->import;
				}

				if (isset($this->config->priorities->{$label->name})) {
					$label->color = $this->getColor(
						$this->config->priorities->{$label->name}
					);
				}

				$labels[] = $label;
			}
		}

		if (isset($this->config->resolutions->{$ticket->resolution})) {
			$config = $this->config->resolutions->{$ticket->resolution};
			if (isset($config->import)) {
				$label = new \stdClass();

				if ($config->import === true) {
					$label->name = $ticket->resolution;
				} else {
					$label->name = $config->import;
				}

				if (isset($this->config->resolutions->{$label->name})) {
					$label->color = $this->getColor(
						$this->config->resolutions->{$label->name}
					);
				}

				$labels[] = $label;
			}
		}

		return $labels;
	}

	// }}}
	// {{{ getIssueBody()

	protected function getIssueBody(\stdClass $ticket)
	{
		$body = sprintf('Trac Ticket #%s', $ticket->id);

		$filteredCCs = array();

		// Get ticket CCs
		if ($ticket->cc != '') {
			$ccs = trim(preg_replace('/[,\s]+/', ' ', $ticket->cc));
			$ccs = explode(' ', $ccs);
			foreach ($ccs as $cc) {
				$cc = $this->getUser($cc);
				if ($cc !== null) {
					$filteredCCs[] = '@' . $cc;
				}
			}
		}

		// Github can only have one assignee, add extra assignees to the CC
		// row.
		$names = trim(preg_replace('/[,\s]+/', ' ', $ticket->owner));
		$names = explode(' ', $names);
		if (count($names) > 1) {
			array_shift($names);
			foreach ($names as $cc) {
				$cc = $this->getUser($cc);
				if ($cc !== null) {
					$filteredCCs[] = '@' . $cc;
				}
			}
		}

		// remove duplicates in case user was CC'd and assigned
		$filteredCCs = array_unique($filteredCCs);

		// Add ticket CC list as second line.
		if (count($filteredCCs) > 0) {
			$body .= "\n\n";
			$body .= "CC'd: " . implode(', ', $filteredCCs);
		}

		// convert body to markdown
		if (!empty($row->description)) {
			$body .= "\n\n";
			$body .= MoinMoin2Markdown::convert($ticket->description);
		}

		return $body;
	}

	// }}}
	// {{{ getIssueAsignee()

	protected function getIssueAsignee($name)
	{
		$name = trim(preg_replace('/[,\s]+/', ' ', $name));

		// Github can only have one assignee, just take first one
		$names = explode(' ', $name);
		$name  = $names[0];

		return $this->getUser($name);
	}

	// }}}
	// {{{ getUser()

	protected function getUser($tracUser, $default = null)
	{
		$user = $default;

		$tracUser = strtolower($tracUser);

		if (isset($this->config->users->$tracUser)) {
			$user = $this->config->users->$tracUser;
		}

		return $user;
	}

	// }}}

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
