<?php

/**
 * @author: Önder Ceylan <onderceylan@gmail.com>
 * @copyright Copyright (c) 2014, Önder Ceylan
 * @url https://github.com/onderceylan/deploy-bitbucket
 * Version: 1.0
 * Date: 6/29/14
 * Time: 1:45 PM
 */

class DeployBitbucket
{
    /**
     * Object that holds configuration data.
     * @var Object
     */
    public static $config;

    /**
     * Post data object which holds push information of your repo.
     * @var object
     */
    private $payload;

    /**
     * Parse post data and deploy matched branches.
     */
    public function __construct($configFile) {
        try {
            // Set default timezone for log file
            date_default_timezone_set(self::$config->logging->timezone);

            self::$config = $this->getConfig($configFile);

            $postData = isset($_POST['payload']) ? $_POST['payload'] : false;

            if ($postData) {
                if ($_SERVER['HTTP_USER_AGENT'] !== 'Bitbucket.org') {
                    header("HTTP/1.1 403 Forbidden");
                    throw new Exception('Unattended access with user agent ' . $_SERVER['HTTP_USER_AGENT']);
                }

                $this->payload = json_decode($postData);

                $branches = $this->getBranches();

                if (empty($branches['matchedBranchNames'])) {
                    throw new Exception('Deployment branch has not found in branches: ' . implode(', ', $branches['pushedBranchNames']));
                } else {
                    foreach ($branches['matchedBranchNames'] as $matchedSite) {
                        foreach (self::$config->sites as $site) {
                            if ($site->branch === $matchedSite) {
                                $this->deploy($site);
                            }
                        }
                    }
                }

            } else {
                header("HTTP/1.1 403 Forbidden");
                throw new Exception('Correct post data required for this script to run');
            }

        } catch (Exception $e) {
            $this->log($e->getMessage(), 'ERROR');
        }
    }

    /**
     * Parses push data, returns pushed branch names and
     * authorized matched branch names.
     *
     * @return array With keys 'pushedBranchNames' and 'matchedBranchNames'
     * @throws Exception
     */
    private function getBranches() {
        try {

            // Check if there are any commits in push data
            if (count($this->payload->commits) > 0) {

                $pushedBranchNames = [];
                $matchedBranchNames = [];

                foreach ($this->payload->commits as $commit) {

                    // Collect branch names from push data
                    $pushedBranchNames[] = $commit->branch;

                    foreach (self::$config->sites as $site) {
                        if ($site->branch === $commit->branch) {

                            // Check if user is authorized to deploy this branch to server
                            if (in_array($this->payload->user, $site->authorizedUsers)) {

                                // Collect matched branch names in both config file and push data
                                $matchedBranchNames[] = $commit->branch;
                            } else {
                                throw new Exception('User ' . $this->payload->user . ' is not authorized to deploy \'' . $site->name . '\' site');
                            }
                        }
                    }
                }

                return array('pushedBranchNames' => $pushedBranchNames, 'matchedBranchNames' => $matchedBranchNames);

            } else {
                throw new Exception('Commit has not found in payload');
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Executes shell commands to deploy the branch in specified folder.
     *
     * @param Object $site Matched site configuration data
     * @throws Exception
     */
    public function deploy($site) {
        try {
            $site->directory = realpath($site->directory) . DIRECTORY_SEPARATOR;

            // Check if specified site folders exist on server
            if (!file_exists($site->directory) && !is_dir($site->directory)) {
                throw new Exception('Couldn\'t find directory (' . $site->directory . ') on server which specified in config for site ' . $site->name);
            }

            // Check if PHP exec() function enabled on server
            if (!function_exists('exec') && !$this->isExecEnabled()) {
                throw new Exception('PHP exec() function is not enabled on the server. Please see README file for instructions.');
            }

            $this->log('Attempting deployment for ' . $site->branch . ' by ' . $this->payload->user);

            // Fetch repository
            exec('cd ' . $site->directory . ' && ' . self::$config->gitPath . ' fetch', $output);
            $this->log('Fetching repo on' . $site->directory . implode(PHP_EOL, $output));

            // Discard any changes to tracked files since last deploy and remove untracked file if configured to do so
            exec(('cd ' . $site->directory . ' && ' . self::$config->gitPath . ' reset --hard HEAD') . (($site->clearDirectoryOnDeploy === true) ? ' && ' . self::$config->gitPath . ' clean -f' : ''), $output);
            $this->log('Reseting repository ' . implode(PHP_EOL, $output));
            if (empty($output)) {
                throw new Exception('Resetting repository failed');
            }

            // Update repo on the server
            exec('cd ' . $site->directory . ' && ' . self::$config->gitPath . ' pull ' . $site->remote . ' ' . $site->branch, $output);
            $this->log('Pulling in changes ' . implode(PHP_EOL, $output));
            if (empty($output)) {
                throw new Exception('Pulling changes failed');
            }

            // Update submodules
            if ($site->hasSubmodules === true) {
                exec('cd ' . $site->directory . ' && git submodule update --recursive', $output);
                $this->log('Updating submodules ' . implode(PHP_EOL, $output));
            }

            if (self::$config->logging->logPayloadData) {
                $this->log('Payload data as follows' . PHP_EOL . serialize($_POST['payload']));
            }

            if (self::$config->logging->logServerRequest) {
                $this->log('Server data as follows' . PHP_EOL . serialize($_SERVER));
            }

            $this->log('Deployment successful' . PHP_EOL);

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Reads config file and decodes it's JSON data.
     *
     * @param string $configFilePath It's passed from client script with classes constructor method
     * @return Object
     * @throws Exception
     */
    private function getConfig($configFilePath) {
        try {
            $configFile = file_get_contents($configFilePath, true);

            // Check if config file placed in right place and readable
            if (!$configFile) {
                throw new Exception('Config file could not read: ' . $configFilePath);
            }

            // Set config object in class
            $configData = json_decode($configFile);

            // Check if any site is defined in config
            if (empty($configData->sites)) {
                throw new Exception('Couldn\'t find any site in config. You must specify at least one site to be deployed');
            }

            return $configData;

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Logs a message to the log file.
     *
     * @param string $message The message to log
     * @param string $type The type of message (INFO, ERROR and etc.)
     */
    public function log($message, $type = 'INFO') {

        if (self::$config->logging->enabled) {
            $filename = self::$config->logging->logFilePath;

            // Create log file if not exist and make it writable
            if (!file_exists($filename) && !is_writable($filename)) {
                file_put_contents($filename, '');
                chmod($filename, 0666);
            }

            // Write message into log file
            file_put_contents($filename, date(self::$config->logging->dateFormat) . ' - [' . $type . '] ' . $message . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * Check if PHP exec() enabled on the server.
     * @return bool
     */
    private function isExecEnabled() {
        static $enabled;

        if (!isset($enabled)) {
            $enabled = true;
            // Check safe_mode is enabled in php.ini
            if (ini_get('safe_mode')) {
                $enabled = false;
            } else {
                // Check if exec is defined in disable_functions var in php.ini
                $disableFunctions = ini_get('disable_functions');
                if ($disableFunctions) {
                    $array = preg_split('/,\s*/', $disableFunctions);
                    if (in_array('exec', $array)) {
                        $enabled = false;
                    }
                }
            }
        }
        return $enabled;
    }
}
