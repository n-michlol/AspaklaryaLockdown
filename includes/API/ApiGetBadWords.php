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
            if ($socket) {
                $writen = fwrite($socket, $text);
                if ($writen === false) {
                    return ['error' => 'Error writing to socket', 'code' => 500];
                }
                $result = fread($socket, 1024 * 1024);
                if ($result === false) {
                    return ['error' => 'Error reading from socket', 'code' => 500];
                }
                return json_decode($result ?? '[]');
            } else {
                return ['error' => $errorMessage, 'code' => $errorCode];
            }
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
