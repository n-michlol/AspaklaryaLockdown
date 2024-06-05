<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use InvalidArgumentException;
use Iterator;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

class ALPageslist implements Iterator {
    /** @var Title[] */
    protected $pages = [];

    /** @var IResultWrapper */
    protected $res;

    /** @var ALPageItem */
    protected $current;

    /**
     * @param string[] $titles full page titles including namespace
     * @param int[] $ids page IDs
     */
    public function __construct( $titles = [], $ids = [] ) {
        if ( count($titles) === 0 && count($ids) === 0 ) {
            throw new InvalidArgumentException( 'ALPageslist must be given at least one title or ID' );
        }
        foreach ( $titles as $title ) {
            $t = Title::newFromText( $title );
            if ( !$t ) {
                throw new InvalidArgumentException( "Invalid title: $title" );
            }
            $this->pages[] = $t;
        }
    }
     /**
     * Get the current list item, or false if we are at the end
     * @return ALPageItem|false
     */
    public function current() {
        return $this->current;
    }
    /**
	 * Move the iteration pointer to the next list item
	 * @return ALPageItem
	 */
    public function next(): void {
        $this->initCurrent();
    }
    public function rewind(): void {
        $this->reset();
    }
    public function key(): int {
		return $this->res ? $this->res->key() : 0;
	}

	public function valid(): bool {
		return $this->res ? $this->res->valid() : false;
	}
    /**
	 * Initialise the current iteration pointer
	 */
	protected function initCurrent() {
        $row = $this->res->current();
		if ( $row ) {
			$this->current = $this->newItem( $row );
		} else {
			$this->current = false;
		}
    }

    /**
	 * Start iteration. This must be called before current() or next().
	 * @return ALPageItem First list item
	 */
	public function reset() {
        if ( !$this->res ) {
			$this->res = $this->doQuery( wfGetDB( DB_REPLICA ) );
		} else {
			$this->res->rewind();
		}
		$this->initCurrent();
		return $this->current;
    }

    /**
	 * Get the number of items in the list.
	 * @return int
	 */
	public function length() {
		if ( !$this->res ) {
			return 0;
		} else {
			return $this->res->numRows();
		}
	}

   public function newItem( $row ) {
        return new ALPageItem( $row );
   }

    /**
	 * Do the DB query to iterate through the objects.
	 * @param IDatabase $db DB object to use for the query
	 * @return IResultWrapper
	 */
   public function doQuery( $db ) {
        $titles = [];
        
        foreach ( $this->pages as $page ) {
            $titles[] = [
                'page_namespace' => $page->getNamespace(), 
                'page_title' => $page->getDBkey()
            ];
        }
        $res = $db->select(
            ['page', ALDBData::PAGES_TABLE_NAME],
            [ 'page_id', 'page_namespace', 'page_title' ],
            $titles,
            __METHOD__
        );
        return $res;
   }

}