<?php


namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
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
        $socket = fsockopen('localhost', 55555, $errorCode, $errorMessage, 10);
        if ($socket) {
            fwrite($socket, $text);
            $result = fread($socket, 1024 * 1024);
            fclose($socket);
        } else {
            return ['error' => $errorMessage, 'code' => $errorCode];
        }

        return json_decode($result ?? '[]');
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
