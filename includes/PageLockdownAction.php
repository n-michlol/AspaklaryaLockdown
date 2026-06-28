<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA
 *
 * @file
 * @ingroup Actions
 */
namespace MediaWiki\Extension\PageLockdown;

use MediaWiki\Actions\FormlessAction;

/**
 * Handle page protection (action=page-lockdown)
 *
 *
 * @ingroup Actions
 */
class PageLockdownAction extends FormlessAction {

	public function getName() {
		return 'page-lockdown';
	}

	public function onView() {
		return null;
	}

	public function show() {
		$mContext = $this->getContext();

		$form = new PageLockdownForm( $this->getWikiPage(), $mContext );
		$form->execute();
	}

	public function doesWrites() {
		return true;
	}
}
