<?php

namespace silverorange\Trac2Github;

require_once 'Console/CommandLine.php';
require_once 'silverorange/Trac2Github/Github.php';

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
		$parser = \Console_CommandLine::fromXmlFile(
			__DIR__ . '/../../data/cli.xml'
		);
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
			foreach ($res->fetchAll(PDO::FETCH_OBJ) as $row) {
				$resp = $github->addMilestone(
					array(
						'title'       => $row->name,
						'state'       => ($row->completed == 0) ? 'open' : 'closed',
						'description' => (empty($row->description)) ? 'None' : $row->description,
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
						echo "Label {$row->name} converted to {$resp['name']}\n";
					} else {
						// Error
						$error = print_r($resp, 1);
						echo "Failed to convert label {$row->name}: $error\n";
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
