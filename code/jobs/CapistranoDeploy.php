<?php
/**
 * Description of DeployJob
 *
 */

require_once(BASE_PATH.'/vendor/autoload.php');

class CapistranoDeploy {

	public $args;

	public function setUp() {
        	global $databaseConfig;
		DB::connect($databaseConfig);
		chdir(BASE_PATH);
	}

	protected function getLogHandle() {
		$logfile = DEPLOYNAUT_LOG_PATH . '/' . $this->args['logfile'];
		$fh = fopen($logfile, 'a');
		if(!$fh) {
			throw new RuntimeException('Can\'t open file "'.$logfile.'" for logging.');
		}
		return $fh;
	}

	public function perform() {
		$environment = $this->args['environment'];
		$repository = $this->args['repository'];
		$sha = $this->args['sha'];
		$projectName = $this->args['projectName'];
		$env = $this->args['env'];

		$project = DNProject::get()->filter('Name', $projectName)->first();
		GraphiteDeploymentNotifier::notify_start($environment, $sha, null, $project);

		$fh = $this->getLogHandle();
		fwrite($fh, '['.date('Y-m-d H:m:s').'] Deploying "'.$sha.'" to "'.$projectName.':'.$environment.'"'.PHP_EOL);
		echo "[-] CapistranoDeploy starting" . PHP_EOL;

		$command = $this->getCommand($projectName.':'.$environment, $repository, $sha, $env);
		$command->run(function ($type, $buffer) use($fh) {
			do {
				usleep(rand(1, 10000));
			} while (!flock($fh, LOCK_EX));
			fwrite($fh, $buffer);
			flock($fh, LOCK_UN);
		});
		if(!$command->isSuccessful()) {
			throw new RuntimeException($command->getErrorOutput());
		}

		fwrite($fh, '['.date('Y-m-d H:m:s').'] Deploy done "'.$sha.'" to "'.$projectName.':'.$environment.'"'.PHP_EOL);

		fclose($fh);

		GraphiteDeploymentNotifier::notify_end($environment, $sha, null, $project);
	}

	/**
	 *
	 * @param string $environment
	 * @param string $repository
	 * @param string $sha
	 * @param string $logfile
	 * @return \Symfony\Component\Process\Process
	 */
	protected function getCommand($environment, $repository, $sha, $env) {
		// Inject env string directly into the command.
		// Capistrano doesn't like the $process->setEnv($env) we'd normally do below.
		$envString = '';
		if (!empty($env)) {
			$envString .= 'env ';
			foreach ($env as $key => $value) {
				$envString .= "$key=\"$value\" ";
			}
		}

		// Generate a capfile from a template
		$capTemplate = file_get_contents(BASE_PATH.'/deploynaut/Capfile.template');
		$cap = str_replace(
			array('<config root>', '<ssh key>', '<base path>'),
			array(DEPLOYNAUT_ENV_ROOT, DEPLOYNAUT_SSH_KEY, BASE_PATH),
			$capTemplate);
		
		if(defined('DEPLOYNAUT_CAPFILE')) {
			$capFile = DEPLOYNAUT_CAPFILE;
		} else {
			$capFile = BASE_PATH.'/assets/Capfile';
		}
		file_put_contents($capFile, $cap);

		$command = "{$envString}cap -f " . escapeshellarg($capFile) . " -vv $environment deploy";
		$command.= ' -s repository='.$repository;
		$command.= ' -s branch='.$sha;
		$command.= ' -s history_path='.realpath(DEPLOYNAUT_LOG_PATH.'/');

		$fh = $this->getLogHandle();
		fwrite($fh, '['.date('Y-m-d H:m:s')."] Running command: $command" . PHP_EOL);

		$process = new \Symfony\Component\Process\Process($command);
		// Capistrano doesn't like it - see comment above.
		//$process->setEnv($env);
		$process->setTimeout(3600);
		return $process;
	}

}