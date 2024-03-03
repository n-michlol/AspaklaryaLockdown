<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use LogFormatter;
use Message;

class ALLogFormatter extends LogFormatter {
    protected function getMessageParameters() {
        $params = parent::getMessageParameters();
        $type = $this->entry->getFullType();

        if ($type === 'aspaklarya/hide' || $type === 'aspaklarya/unhide') {
            $link = $this->getLinkRenderer()->makeKnownLink(
                $this->entry->getTarget(),
                ['oldid' => $params[5]]
            );
            $params[5] = Message::rawParam($link);
        }
        return $params;
    }
}
