<?php


namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
use MediaWiki;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiGetBadWords extends ApiBase {
    public function execute() {
        if (!$this->getUser()->isAllowed('get-bad-words')) {
            $this->dieWithError('bad-words-permission-denied', 'bad-words-permission-denied');
        }
        $params = $this->extractRequestParams();
        $text = $params['text'];
        $result = $this->checktext($text);
        $this->getResult()->addValue(null, $this->getModuleName(), $result);
    }
    public function checktext(string $text) {
        $errorCode = 0;
        $errorMessage = '';
        try {
            $socket = @fsockopen('unix:///tmp/echo.sock', -1, $errorCode, $errorMessage, 10);
            if (!$socket) {
                $config = $this->getConfig();
                $badWordPath = $config->get('AspaklaryaLockDownBadWordsPath');
                if (empty($badWordPath)) {
                    return ['error' => 'Bad words path is not set', 'code' => 500];
                }
                $dbType = $config->get('DBtype');
                $dbUserName = $config->get('DBuser');
                $dbPassword = $config->get('DBpassword');
                $dbServer = $config->get('DBserver');
                $dbName = $config->get('DBname');
                $params = [
                    "$badWordPath",
                    '--socket-path=/tmp/echo.sock',
                    "--db-type=$dbType",
                    "--db-username=$dbUserName",
                    "--db-password=$dbPassword",
                    "--db-address=$dbServer",
                    "--db-name=$dbName",
                ];

                $commandFactory = MediaWikiServices::getInstance()
                    ->getShellCommandFactory()
                    ->createBoxed("bad-words")
                    ->disableNetwork()
                    ->firejailDefaultSeccomp();
                $result = $commandFactory->routeName($badWordPath)->params($params)->includeStderr()->execute();
                if ($result->getExitCode() !== 0) {
                    return ['error' => 'Error executing bad-words', 'code' => 500, 'output' => $result->getStdout()];
                }
                return ['result' => $result->getStdout()];
                $socket = @fsockopen('unix:///tmp/echo.sock', -1, $errorCode, $errorMessage, 10);
            }
            if ($socket) {
                $writen = fwrite($socket, $text);
                if ($writen === false) {
                    return ['error' => 'Error writing to socket', 'code' => 500];
                }
                $result = fread($socket, 1024 * 1024);
                if ($result === false) {
                    return ['error' => 'Error reading from socket', 'code' => 500];
                }
            } else {
                return ['error' => $errorMessage, 'code' => $errorCode];
            }
            return json_decode($result ?? '[]');
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        } finally {
            if (isset($socket) && is_resource($socket)) {
                fclose($socket);
            }
        }
    }
    /** @inheritDoc */
    public function getAllowedParams() {
        return [
            'text' => [
                ParamValidator::PARAM_TYPE => 'string',
                ApiBase::PARAM_HELP_MSG => 'apihelp-bad-words-param-text',
            ],
        ];
    }

    public function mustBePosted() {
        return true;
    }

    public function needsToken() {
        return 'csrf';
    }

    public function isWriteMode() {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getExamples() {
        return [
            'api.php?text=hello_world' => 'apihelp-bad-words-example-1'
        ];
    }
    public function getHelpUrls() {
        return [''];
    }
}
