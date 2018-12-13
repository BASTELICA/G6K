<?php

/*
The MIT License (MIT)

Copyright (c) 2018 Jacques Archimède

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

namespace App\G6K\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Filesystem\Filesystem;

use App\G6K\Manager\SQLSelectTokenizer;

/**
 * Validates a simulator against the Simulator.xsd schema file.
 *
 */
class ValidateSimulatorCommand extends CommandBase
{

	/**
	 * @var string Table list per data source
	 */
	 private $tables = array();

	/**
	 * @inheritdoc
	 */
	public function __construct(string $projectDir) {
		parent::__construct($projectDir);
	}

	/**
	 * @inheritdoc
	 */
	protected function getCommandName() {
		return 'g6k:simulator:validate';
	}

	/**
	 * @inheritdoc
	 */
	protected function getCommandDescription() {
		return $this->translator->trans('Validates a simulator against the Simulator.xsd schema file.');
	}

	/**
	 * @inheritdoc
	 */
	protected function getCommandHelp() {
		return
			  $this->translator->trans("This command allows you to validates a simulator against the Simulator.xsd schema file.")."\n"
			. "\n"
			. $this->translator->trans("You must provide:")."\n"
			. $this->translator->trans("- the name of the simulator (simulatorname).")."\n"
		;
	}

	/**
	 * @inheritdoc
	 */
	protected function getCommandArguments() {
		return array(
			array(
				'simulatorname',
				InputArgument::REQUIRED,
				$this->translator->trans('The name of the simulator.')
			)
		);
	}

	/**
	 * Checks the argument of the current command (g6k:simulator:import).
	 *
	 * @param   \Symfony\Component\Console\Input\InputInterface $input The input interface
	 * @param   \Symfony\Component\Console\Output\OutputInterface $output The output interface
	 * @return  void
	 *
	 */
	protected function interact(InputInterface $input, OutputInterface $output) {
		$this->askArgument($input, $output, 'simulatorname', "Enter the name of the simulator : ");
	}

	/**
	 * @inheritdoc
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$output->writeln([
			$this->translator->trans("Simulator Validator"),
			'=========================================== ',
			'',
		]);
		$simulatorsDir = $this->projectDir . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'simulators';
		$simufile = $simulatorsDir."/".$input->getArgument('simulatorname') . ".xml";
		if (! file_exists($simufile)) {
			$output->writeln($this->translator->trans("The simulator XML file '%s%' doesn't exists", array('%s%' => $simufile)));
			return 1;
		}
		$output->writeln($this->translator->trans("Validating the simulator '%simulatorname%' located in '%simulatorpath%'", array('%simulatorname%' => $input->getArgument('simulatorname'), '%simulatorpath%' => $simulatorsDir)));
		$simulator = new \DOMDocument();
		$simulator->preserveWhiteSpace  = false;
		$simulator->formatOutput = true;
		libxml_use_internal_errors(true);
		$simulator->load($simufile);
		if (!$this->validatesAgainstSchema($simulator, $output)) {
			return 1;
		}
		$simulatorxpath = new \DOMXPath($simulator);
		$simu = $simulator->documentElement->getAttribute('name');
		$datasourcesfile = $this->projectDir."/var/data/databases/DataSources.xml";
		$datasources = new \DOMDocument();
		$datasources->preserveWhiteSpace  = false;
		$datasources->formatOutput = true;
		$datasources->load($datasourcesfile);
		$datasourcesxpath = new \DOMXPath($datasources);
		$exitcode = 0;
		if ($simulator->documentElement->hasAttribute('defaultView') && ! $this->checkDefaultViewElements($simu, $simulatorxpath, $output)) {
			$exitcode = 1;
		}
		if (! $this->checkDataReferences($simu, $simulatorxpath, $output)) {
			$exitcode = 1;
		}
		if (! $this->checkSources($simu, $simulatorxpath, $datasourcesxpath, $output)) {
			$exitcode = 1;
		}
		if (! $this->checkBusinessRules($simu, $simulatorxpath, $output)) {
			$exitcode = 1;
		}
		if ($exitcode == 0) {
			$output->writeln($this->translator->trans("The simulator '%s%' is successfully validated", array('%s%' => $simu)));
		} else {
			$output->writeln($this->translator->trans("The simulator xml file of '%s%' has some errors.", array('%s%' => $simu)));
		}
		return $exitcode;
	}

	/**
	 * Validates the simulator against its schema
	 *
	 * @access  private
	 * @param   \DOMDocument $simulator The simulator document
	 * @param   \Symfony\Component\Console\Output\OutputInterface $output The output interface
	 * @return  bool true if simulator is valid, false if not.
	 *
	 */
	private function validatesAgainstSchema(\DOMDocument $simulator, OutputInterface $output) {
		$schema = $this->projectDir."/var/doc/Simulator.xsd";
		if (!$simulator->schemaValidate($schema)) {
			$errors = libxml_get_errors();
			$mess = "";
			foreach ($errors as $error) {
				$mess .= "Line ".$error->line . '.' .  $error->column . ": " .  $error->message . "\n";
			}
			libxml_clear_errors();
			$output->writeln([
				$this->translator->trans("XML Validation errors:"),
				$mess
			]);
			return false;
		}
		return true;
	}

	/**
	 * Checks the existence of the elements of the default view
	 *
	 * @access  private
	 * @param   string $simu The simulator name
	 * @param   \DOMXPath $simulatorxpath The simulator xpath
	 * @param   \Symfony\Component\Console\Output\OutputInterface $output The output interface
	 * @return  bool true if the elements exist, false if not.
	 *
	 */
	private function checkDefaultViewElements(string $simu, \DOMXPath $simulatorxpath, OutputInterface $output) {
		$ok = true;
		$assetsDir = $this->projectDir."/".$this->parameters['public_dir']."/assets";
		$viewsDir = $this->projectDir."/templates";
		$fsystem = new Filesystem();
		$view = $simulatorxpath->query("/Simulator/@defaultView")->item(0)->nodeValue;
		if (! $fsystem->exists($assetsDir.'/'.$view.'/css/'.$simu.'.css')) {
			$output->writeln($this->translator->trans("The stylesheet associated to '%simulatorname%' doesn't exists.", array('%simulatorname%' => $simu)));
			$ok = false;
		}
		$steps = $simulatorxpath->query("/Simulator/Steps/Step");
		$len = $steps->length;
		for($i = 0; $i < $len; $i++) {
			$step = $this->getDOMElementItem($steps, $i);
			$template = str_replace(':', '/', $step->getAttribute('template'));
			if (! $fsystem->exists($viewsDir.'/'.$view.'/'.$template)) {
				$output->writeln($this->translator->trans("In line %line%, the template '%template%' associated to step %step% of '%simulatorname%' doesn't exists.", array('%line%' => $step->getLineNo(), '%template%' => $template, '%step%' => $step->getAttribute('id'), '%simulatorname%' => $simu)));
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Checks the references of data
	 *
	 * @access  private
	 * @param   string $simu The simulator name
	 * @param   \DOMXPath $simulatorxpath The simulator xpath
	 * @param   \Symfony\Component\Console\Output\OutputInterface $output The output interface
	 * @return  bool true if the references are valids, false if not.
	 *
	 */
	private function checkDataReferences(string $simu, \DOMXPath $simulatorxpath, OutputInterface $output) {
		$ok = true;
		$dataRefs = $simulatorxpath->query("//Field/@data|//Parameter/@data|//Profile/Data/@id");
		foreach($dataRefs as $ref) {
			$data = $ref->nodeValue;
			$datas = $simulatorxpath->query("//Data[@id='".$data."']");
			if ($datas->length == 0) {
				$output->writeln($this->translator->trans("In line %line%, the data '%data%' referenced by an element '%element%' of '%simulatorname%' doesn't exists.", array('%line%' => $ref->getLineNo(), '%data%' => $data, '%element%' => $ref->ownerElement->getNodePath(), '%simulatorname%' => $simu)));
				$ok = false;
			}
		}
		$texts = $simulatorxpath->query("//Description|//Legend|//FootNote|//PreNote|//PostNote|//Section|//Annotations|//Conditions/@value|//Condition/@expression|//DataSet/Data/@content|//DataSet/Data/@default|//DataSet/Data/@min|//DataSet/Data/@max|//BusinessRule//Action/@value");
		foreach($texts as $text) {
			if (preg_match_all("|\#(\d+)|",  $text->nodeValue, $m) !== false) {
				foreach($m[1] as $data) {
					$datas = $simulatorxpath->query("//DataSet/Data[@id='".$data."']");
					if ($datas->length == 0) {
						$output->writeln($this->translator->trans("In line %line%, the data '%data%' referenced in the element '%element%' text of '%simulatorname%' doesn't exists.", array('%line%' => $text->getLineNo(), '%data%' => $data, '%element%' => $text->getNodePath(), '%simulatorname%' => $simu)));
						$ok = false;
					}
				}
			}
		}
		return $ok;
	}

	/**
	 * Checks the data sources
	 *
	 * @access  private
	 * @param   string $simu The simulator name
	 * @param   \DOMXPath $simulatorxpath The simulator xpath
	 * @param   \DOMXPath $datasourcesxpath The datasources xpath
	 * @param   \Symfony\Component\Console\Output\OutputInterface $output The output interface
	 * @return  bool true if the sources are valids, false if not.
	 *
	 */
	private function checkSources(string $simu, \DOMXPath $simulatorxpath, \DOMXPath $datasourcesxpath, OutputInterface $output) {
		$ok = true;
		$tokenizer = new SQLSelectTokenizer();
		$sources = $simulatorxpath->query("/Simulator/Sources/Source");
		$len = $sources->length;
		if ($len > 0) {
			for($i = 0; $i < $len; $i++) {
				$source = $this->getDOMElementItem($sources, $i);
				$datasourcename = $source->getAttribute('datasource');
				if (is_numeric($datasourcename)) {
					$datasource = $datasourcesxpath->query("/DataSources/DataSource[@id='".$datasourcename."']");
				} else {
					$datasource = $datasourcesxpath->query("/DataSources/DataSource[@name='".$datasourcename."']");
				}
				if ($datasource->length == 0) {
					$output->writeln($this->translator->trans("In line %line%, the '%datasource%' associated to source %source% of '%simulatorname%' doesn't exists.", array('%line%' => $source->getLineNo(), '%datasource%' => $datasourcename, '%source%' => $source->getAttribute('id'), '%simulatorname%' => $simu)));
					$ok = false;
				} else {
					$id = $source->getAttribute('id');
					$indexes = $simulatorxpath->query("//Sources/Source[@id='".$id ."']/@returnPath|//Data[@source='".$id ."']/@index|//Data/Choices/Source[@id='".$id ."']/@valueColumn|//Data/Choices/Source[@id='".$id ."']/@labelColumn");
					if ($indexes->length > 0) {
						$datasource = $this->getDOMElementItem($datasource, 0);
						$requestType = $source->hasAttribute('requestType') ? $source->getAttribute('requestType'): 'simple';
						$request = $source->getAttribute('request');
						if ($request != "" && $requestType == "simple") {
							$datasourceid = $datasource->getAttribute('id');
							$datasourcename = $datasource->getAttribute('name');
							if (!isset($this->tables[$datasourcename])) {
								$this->tables[$datasourcename] = $this->parseDatasourceTables($datasourceid, $datasourcesxpath, $output);
							}
							$tokenizer->setTables($this->tables[$datasourcename]);
							$parsed = $tokenizer->parseSetOperations($request);
							$columns = array();
							foreach($parsed->select as $column) {
								$columns[] = $column->alias;
							}
							foreach($indexes as $index) {
								$acolumns = array();
								if ($index->nodeName == 'returnPath') {
									$parts = explode("/", $index->nodeValue);
									foreach($parts as $part) {
										if (!is_numeric($part)) {
											$acolumns[] = $part;
										}
									}
								} else {
									$acolumns[] = preg_replace("/(^'|'$)/", "", $index->nodeValue);
								}
								foreach($acolumns as $column) {
									if (!in_array($column, $columns)) {
										$output->writeln($this->translator->trans("In line %line%, the column '%column%' of '%attribute%' in '%element%' isn't returned by the source %source% of '%simulatorname%'.", array('%line%' => $source->getLineNo(), '%column%' => $column, '%attribute%' => $index->nodeName, '%element%' => $index->ownerElement->getNodePath(), '%source%' => $id, '%simulatorname%' => $simu)));
										$ok = false;
									}
								}
							}
						}
					}
				}
			}
		}
		$sourceRefs = $simulatorxpath->query("//Data/@source|//Choices/Source/@id");
		foreach($sourceRefs as $ref) {
			$source = $ref->nodeValue;
			$sources = $simulatorxpath->query("//Sources/Source[@id='".$source."']");
			if ($sources->length == 0) {
				$output->writeln($this->translator->trans("In line %line%, the source '%source%' used by an element '%element%' of '%simulatorname%' doesn't exists.", array('%line%' => $ref->getLineNo(), '%source%' => $source, '%element%' => $ref->ownerElement->getNodePath(), '%simulatorname%' => $simu)));
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Checks the business rules
	 *
	 * @access  private
	 * @param   string $simu The simulator name
	 * @param   \DOMXPath $simulatorxpath The simulator xpath
	 * @param   \Symfony\Component\Console\Output\OutputInterface $output The output interface
	 * @return  bool true if the rules are valids, false if not.
	 *
	 */
	private function checkBusinessRules(string $simu, \DOMXPath $simulatorxpath, OutputInterface $output) {
		$ok = true;
		$operands = $simulatorxpath->query("//Condition/@operand");
		foreach($operands as $operand) {
			$datas = $simulatorxpath->query("//DataSet/Data[@name='".$operand->nodeValue."']");
			if ($datas->length == 0) {
				$output->writeln($this->translator->trans("In line %line%, the data '%data%' used in the element '%element%' of '%simulatorname%' doesn't exists.", array('%line%' => $operand->getLineNo(), '%data%' => $operand->nodeValue, '%element%' => $operand->ownerElement->getNodePath(), '%simulatorname%' => $simu)));
				$ok = false;
			}
		}
		$operators = $simulatorxpath->query("//Condition/@operator");
		foreach($operators as $operator) {
			if (! in_array($operator->nodeValue, ['=', '!=', '>', '>=', '<', '<=', 'isTrue', 'isFalse', '~', '!~', 'matches', 'present', 'blank'])) {
				$output->writeln($this->translator->trans("In line %line%, the operator '%operator%' used in the element '%element%' of '%simulatorname%' is invalid.", array('%line%' => $operator->getLineNo(), '%operator%' => $operator->nodeValue, '%element%' => $operator->ownerElement->getNodePath(), '%simulatorname%' => $simu)));
				$ok = false;
			}
			if (in_array($operator->nodeValue, ['=', '!=', '>', '>=', '<', '<=', '~', '!~', 'matches']) && ! $operator->ownerElement->hasAttribute('expression')) {
				$output->writeln($this->translator->trans("In line %line%, the expression is required when the operator '%operator%' is used in the element '%element%' of '%simulatorname%'", array('%line%' => $operator->getLineNo(), '%operator%' => $operator->nodeValue, '%element%' => $operator->ownerElement->getNodePath(), '%simulatorname%' => $simu)));
				$ok = false;
			}
			if (in_array($operator->nodeValue, ['isTrue', 'isFalse', 'present', 'blank']) && $operator->ownerElement->hasAttribute('expression')) {
				$output->writeln($this->translator->trans("In line %line%, the expression must not be used with the operator '%operator%' in the element '%element%' of '%simulatorname%'", array('%line%' => $operator->getLineNo(), '%operator%' => $operator->nodeValue, '%element%' => $operator->ownerElement->getNodePath(), '%simulatorname%' => $simu)));
				$ok = false;
			}
		}
		$actions = $simulatorxpath->query("//BusinessRule//Action");
		for($i = 0; $i < $actions->length; $i++) {
			$action = $this->getDOMElementItem($actions, $i);
			$query = $this->makeQuery($action);
			$targets = $simulatorxpath->query($query);
			if ($targets->length == 0) {
				$targetname = $action->getAttribute('target');
				if (in_array($action->getAttribute('target'), ['content', 'default', 'min', 'max','index'])) {
					if ($action->hasAttribute('data')) {
						$targetname = 'data';
					} elseif ($action->hasAttribute('datagroup')) {
						$targetname = 'datagroup';
					} else {
						$targetname = 'dataset';
					}
				}
				$output->writeln($this->translator->trans("In line %line%, the '%targetname%' '%target%' referenced in the rule action '%element%' of '%simulatorname%' doesn't exists.", array('%line%' => $action->getLineNo(), '%targetname%' => $targetname, '%target%' => $action->getAttribute($targetname), '%element%' => $action->getNodePath(), '%simulatorname%' => $simu)));
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Computes a xpath query to access the target element of a rule action
	 *
	 * @access  private
	 * @param   \DOMElement $action The rule action
	 * @return  string The computed xpath query.
	 *
	 */
	private function makeQuery(\DOMElement $action) {
		$path = ['Data'=>'id', 'DataGroup'=>'id', 'Step'=>'id', 'FootNote'=>'id', 'ActionList/Action'=>'name', 'Data//Choice'=>'id', 'Panel'=>'id', 'FieldSet'=>'id', 'FieldRow'=>'id', 'Field'=>'position', 'PreNote'=>'position', 'PostNote'=>'position', 'BlockInfo'=>'id', 'Chapter'=>'id', 'Section'=>'id'];
		$query = "";
		foreach($path as $element => $id) {
			$attr = strtolower(preg_replace("|^.+/|", "", $element));
			if ($action->hasAttribute($attr)) {
				if ($attr == 'prenote' || $attr == 'postnote') {
					$query .= "//Field[@".$id."='".$action->getAttribute($attr)."']";
				} else {
					$query .= "//".$element."[@".$id."='".$action->getAttribute($attr)."']";
				}
			}
		}
		return $query;
	}

	/**
	 * Extracts the tables of the given datasource id from the DataSources.xml file
	 *
	 * @access  private
	 * @param   int $id The datasource id
	 * @param   \DOMXPath $datasourcesxpath The datasources xpath
	 * @param   \Symfony\Component\Console\Output\OutputInterface $output The output interface
	 * @return  array The parsed tables.
	 *
	 */
	private function parseDatasourceTables(int $id, \DOMXPath $datasourcesxpath, OutputInterface $output) {
		$tables = array();
		$datasources = $datasourcesxpath->query("/DataSources/DataSource[@id='" . $id . "']");
		if ($datasources->length > 0) {
			$datasource = $this->getDOMElementItem($datasources, 0);
			if (in_array($datasource->getAttribute('type'), ['internal', 'database'])) {
				$dstables = $datasource->getElementsByTagName('Table');
				for($t = 0; $t < $dstables->length; $t++) {
					$table = $this->getDOMElementItem($dstables, $t);
					$columns = array();
					$dscolumns = $datasource->getElementsByTagName('Column');
					for($c = 0; $c < $dscolumns->length; $c++) {
						$column = $this->getDOMElementItem($dscolumns, $c);
						$choices = array();
						if ($column->getAttribute('type') == 'choice') {
							$dschoices = $datasource->getElementsByTagName('Choices');
							if ($dschoices->length > 0) {
								$dschoices = $this->getDOMElementItem($dschoices, 0);
								$dschoices = $dschoices->getElementsByTagName('Choice');
								for($ch = 0; $ch < $dschoices->length; $ch++) {
									$choice = $this->getDOMElementItem($dschoices, $ch);
									$choices[] = [
										'id' => (int)$choice->getAttribute('id'),
										'value' => $choice->getAttribute('value'),
										'label' => $choice->getAttribute('label')
									];
								}
							}
						}
						$columns[strtolower($column->getAttribute('name'))] = [
							'id' => (int)$column->getAttribute('id'),
							'name' => $column->getAttribute('name'),
							'type' => $column->getAttribute('type'),
							'label' => $column->getAttribute('label'),
							'description' => "", // $column->Description,
							'choices' => $choices
						];
					}
					$tables[strtolower($table->getAttribute('name'))] = [
						'id' => (int)$table->getAttribute('id'),
						'name' => $table->getAttribute('name'),
						'label' => $table->getAttribute('label'),
						'description' => "", // $table->Description,
						'columns' => $columns
					];
				}
			}
		}
		return $tables;
	}

	/**
	 * Retuns the DOMElement at position $index of the DOMNodeList
	 *
	 * @access  private
	 * @param   \DOMNodeList $nodes The DOMNodeList
	 * @param   int $index The position in the DOMNodeList
	 * @return  \DOMElement|null The DOMElement.
	 *
	 */
	private function getDOMElementItem($nodes, $index) {
		$node = $nodes->item($index);
		if ($node && $node->nodeType === XML_ELEMENT_NODE) {
			return $node;
		}
		return null;
	}
}
