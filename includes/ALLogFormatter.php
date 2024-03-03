<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use LogFormatter;
use Message;

class ALLogFormatter extends LogFormatter {
    public function getMessageParameters() {
        $params = parent::getMessageParameters();
        $type = $this->entry->getFullType();

        if ($type === 'aspaklarya/hide' || $type === 'aspaklarya/unhide') {
            $link = $this->getLinkRenderer()->makeKnownLink(
                $this->entry->getTarget(),
                ['oldid' => $params[4]]
            );
            $params[4] = Message::rawParam($link);
        }
        return $params;
    }
}
