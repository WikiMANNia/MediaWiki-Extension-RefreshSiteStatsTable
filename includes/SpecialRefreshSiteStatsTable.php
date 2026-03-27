<?php
/**
 * SpecialPage for RefreshSiteStatsTable extension
 *
 * Copyright © 2024 WikiMANNia <chef@wikimannia.org>
 * https://www.wikimannia.org/
 *
 * This program is free software.
 *
 * @file
 */

namespace MediaWiki\Extension\RefreshSiteStatsTable;

use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\Xml\Xml;

// Class aliases for multi-version compatibility.
// These need to be in global scope so phan can pick up on them,
// and before any use statements that make use of the namespaced names.
if ( version_compare( MW_VERSION, '1.40', '<' ) ) {
	if ( !class_exists('MediaWiki\Html\Html') )  class_alias( '\Html', '\MediaWiki\Html\Html' );
	if ( !class_exists('MediaWiki\Title\Title') )  class_alias( '\Title', '\MediaWiki\Title\Title' );
}

if ( version_compare( MW_VERSION, '1.41', '<' ) ) {
	if ( !class_exists('MediaWiki\SpecialPage\SpecialPage') )  class_alias( '\SpecialPage', '\MediaWiki\SpecialPage\SpecialPage' );
}

if ( version_compare( MW_VERSION, '1.42', '<' ) ) {
	if ( !class_exists('MediaWiki\Context\RequestContext') )  class_alias( '\RequestContext', '\MediaWiki\Context\RequestContext' );
}

if ( version_compare( MW_VERSION, '1.43', '<' ) ) {
	if ( !class_exists('MediaWiki\Xml\Xml') )  class_alias( '\Xml', '\MediaWiki\Xml\Xml' );
}

class SpecialRefreshSiteStatsTable extends SpecialPage {

	private $mDBr;
	private $mDBw;
	private bool $mAllowRefreshSiteStatsTable;
	private string $mErrorClass;
	private string $mSuccessClass;
	private string $mERROR_msg;
	private string $mOK_msg;
	private string $mUNKNOWN_msg;

	/**
	 * Constructor - sets up the new special page
	 */
	public function __construct() {
		parent::__construct( 'RefreshSiteStatsTable' );

		if ( method_exists( '\MediaWiki\MediaWikiServices', 'getConnectionProvider' ) ) {
			$connection_provider = MediaWikiServices::getInstance()->getConnectionProvider();
			$this->mDBr = $connection_provider->getReplicaDatabase();
			$this->mDBw = $connection_provider->getPrimaryDatabase();
		} else {
			$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
			$this->mDBr = $lb->getMaintenanceConnectionRef( DB_REPLICA );
			$this->mDBw = $lb->getConnection( DB_PRIMARY, [], false );
		}
		$ctx = RequestContext::getMain();
		$user = $ctx->getUser();
		$this->mAllowRefreshSiteStatsTable = (bool)$user->isAllowed( 'AllowRefreshSiteStatsTable' );
 		$this->mErrorClass   = 'mw-message-box-error';
 		$this->mSuccessClass = 'mw-message-box-success';
		$this->mERROR_msg   = '<span class="' . $this->mErrorClass . '" style="display:inline; margin:0; padding:2px;">' . $this->msg( 'refreshsitestatstable-status-msg-error' )->text() . '</span>';
		$this->mOK_msg      = '<span class="' . $this->mSuccessClass . '" style="display:inline; margin:0; padding:2px;">' . $this->msg( 'refreshsitestatstable-status-msg-ok' )->text() . '</span>';
		$this->mUNKNOWN_msg = '<span class="' . $this->mErrorClass . '" style="display:inline; margin:0; padding:2px;">' . $this->msg( 'refreshsitestatstable-status-msg-unknown' )->text() . '</span>';
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Show the page to the user
	 *
	 * @param string|null $sub The subpage string argument (if any).
	 */
	public function execute( $sub ) {
		$output = $this->getOutput();
		if ( method_exists( $output, 'setPageTitleMsg' ) ) {
			$output->setPageTitleMsg( $this->msg( 'refreshsitestatstable-title' ) );
		} else {
			$output->setPageTitle( $this->msg( 'refreshsitestatstable-title' ) );
		}
		$output->addWikiMsg( 'refreshsitestatstable-intro' );

		$request = $this->getRequest();
		$this->setHeaders();

		$action = $sub ? $sub : $request->getVal( 'action', $sub );
		$return = $this->getPageTitle();

		if ( $action === 'submit' ) {
			$this->doSubmit();
		} else {
			$this->showForm( $action );
		}

		$output->returnToMain( false, Title::makeTitleSafe( NS_SPECIAL, 'Statistics' ) );
	}

	/**
	 * @param string|null $action The action of the form
	 */
	protected function showForm( $action ) {

		$request = $this->getRequest();
		$submit_action = 'submit';
		$button = 'ok';
		$status_OK = true;
		$anzahl_counted_good   = 0;
		$anzahl_counted_total  = 0;
		$anzahl_counted_images = 0;
		$anzahl_counted_users  = 0;
		$userGroups = [ 'trusted', 'sysop' ];

		$topmessage   = $this->msg( 'refreshsitestatstable-title' )->text();
		$intromessage = '';
		$attrs_disabled = [ 'disabled' => 'disabled' ];
		$label = [ 'class' => 'mw-label' ];
		$input = [ 'class' => 'mw-input' ];
		$submit_button_attrs = [ 'tabindex' => 100, 'id' => 'mw-refreshsitestatstable-{$action}-submit' ];

		$good_articles_status_msg = $total_pages_status_msg = $images_status_msg = $users_status_msg = $this->mOK_msg;
		$anzahl_stat_db_good   = (int)$this->mDBr->selectField( 'site_stats', 'ss_good_articles', [ 'ss_row_id' => 1 ], __METHOD__ );
		$anzahl_stat_db_total  = (int)$this->mDBr->selectField( 'site_stats', 'ss_total_pages', [ 'ss_row_id' => 1 ], __METHOD__ );
		$anzahl_stat_db_images = (int)$this->mDBr->selectField( 'site_stats', 'ss_images', [ 'ss_row_id' => 1 ], __METHOD__ );
		$anzahl_stat_db_users  = (int)$this->mDBr->selectField( 'site_stats', 'ss_users', [ 'ss_row_id' => 1 ], __METHOD__ );
		if ( $this->mAllowRefreshSiteStatsTable ) {
			$anzahl_counted_good   = (int)$this->mDBr->selectField( 'page',  'COUNT(*)', [ 'page_namespace' => NS_MAIN, 'page_is_redirect' => 0 ], __METHOD__ );
			$anzahl_counted_total  = (int)$this->mDBr->selectField( 'page',  'COUNT(*)', [], __METHOD__ );
			$anzahl_counted_images = (int)$this->mDBr->selectField( 'image', 'COUNT(*)', [], __METHOD__ );
			$anzahl_counted_users  = (int)$this->mDBr->selectField( 'user',  'COUNT(*)', [], __METHOD__ );

			if ( $anzahl_counted_good !== $anzahl_stat_db_good ) {
				$status_OK = false;
				$good_articles_status_msg = $this->mERROR_msg;
			}
			if ( $anzahl_counted_total !== $anzahl_stat_db_total ) {
				$status_OK = false;
				$total_pages_status_msg = $this->mERROR_msg;
			}
			if ( $anzahl_counted_images !== $anzahl_stat_db_images ) {
				$status_OK = false;
				$images_status_msg = $this->mERROR_msg;
			}
			if ( $anzahl_counted_users !== $anzahl_stat_db_users ) {
				$status_OK = false;
				$users_status_msg = $this->mERROR_msg;
			}
		} else {
			$status_OK = false;
			$anzahl_counted_good = $anzahl_counted_total = $anzahl_counted_images = $anzahl_counted_users = '&nbsp;?';
			$good_articles_status_msg = $total_pages_status_msg = $images_status_msg = $users_status_msg = $this->mUNKNOWN_msg;
		}
		if ( !$this->mAllowRefreshSiteStatsTable ) {
			$submit_button_attrs = array_merge( $submit_button_attrs, $attrs_disabled );
			$intromessage .= Html::rawElement( 'p', [ 'class' => $this->mErrorClass ], $this->msg( 'badaccess-groups', implode( ', ', $userGroups ), count( $userGroups ) ) );
		} elseif ( $status_OK ) {
			$submit_button_attrs = array_merge( $submit_button_attrs, $attrs_disabled );
			$intromessage .= Html::rawElement( 'p', [], $this->msg( 'refreshsitestatstable-status-msg', $this->msg( 'refreshsitestatstable-status-msg-ok' )->text() )->text() );
		} else {
			$intromessage .= Html::rawElement( 'p', [], $this->msg( 'refreshsitestatstable-status-msg', $this->msg( 'refreshsitestatstable-status-msg-error' )->text() )->text() );
		}

		$formContent2 =
				Html::rawElement( 'tr', [],
					Html::rawElement( 'td', $label,
						'&nbsp;'
					) .
					Html::rawElement( 'td', $label,
						Xml::label( $this->msg( 'refreshsitestatstable-good-articles' )->text(), 'mw-refreshsitestatstable-good-articles'
						)
					) .
					Html::rawElement( 'td', $label,
						Xml::label( $this->msg( 'refreshsitestatstable-total-pages' )->text(), 'mw-refreshsitestatstable-total-pages'
						)
					) .
					Html::rawElement( 'td', $label,
						Xml::label( $this->msg( 'refreshsitestatstable-images' )->text(), 'mw-refreshsitestatstable-images'
						)
					) .
					Html::rawElement( 'td', $label,
						Xml::label( $this->msg( 'refreshsitestatstable-users' )->text(), 'mw-refreshsitestatstable-users'
						)
					)
				) .
				Html::rawElement( 'tr', [],
					Html::rawElement( 'td', $label,
						Xml::label( $this->msg( 'refreshsitestatstable-db-count' )->text(), 'mw-refreshsitestatstable-db-count'
						)
					) .
					Html::rawElement( 'td', $input,
						$anzahl_counted_good
					) .
					Html::rawElement( 'td', $input,
						$anzahl_counted_total
					) .
					Html::rawElement( 'td', $input,
						$anzahl_counted_images
					) .
					Html::rawElement( 'td', $input,
						$anzahl_counted_users
					)
				) .
				Html::rawElement( 'tr', [],
					Html::rawElement( 'td', $label,
						Xml::label( $this->msg( 'refreshsitestatstable-statistic' )->text(), 'mw-refreshsitestatstable-statistic'
						)
					) .
					Html::rawElement( 'td', $input,
						$anzahl_stat_db_good
					) .
					Html::rawElement( 'td', $input,
						$anzahl_stat_db_total
					) .
					Html::rawElement( 'td', $input,
						$anzahl_stat_db_images
					) .
					Html::rawElement( 'td', $input,
						$anzahl_stat_db_users
					)
				) .
				Html::rawElement( 'tr', [],
					Html::rawElement( 'td', $label,
						Xml::label( $this->msg( 'refreshsitestatstable-status' )->text(), 'mw-refreshsitestatstable-status'
						)
					) .
					Html::rawElement( 'td', $input,
						$good_articles_status_msg
					) .
					Html::rawElement( 'td', $input,
						$total_pages_status_msg
					) .
					Html::rawElement( 'td', $input,
						$images_status_msg
					) .
					Html::rawElement( 'td', $input,
						$users_status_msg
					)
				);
		$form =
			Xml::fieldset( $topmessage,
				Html::rawElement(
					'form', [
						'id' => 'mw-refreshsitestatstable-{$action}form',
						'method' => 'post',
						'action' => $this->getPageTitle()->getLocalURL( [ 'action' => $submit_action ] )
					],
					Html::rawElement( 'p', [], $intromessage ) .
					Html::rawElement( 'table', [ 'id' => 'mw-refreshsitestatstable-{$action}' ],
						$formContent2 .
						Html::rawElement( 'tr', [],
							Html::rawElement( 'td', [], '' ) .
							Html::rawElement( 'td', [ 'class' => 'mw-submit' ],
								Html::submitButton( $this->msg( $button )->text(),
									$submit_button_attrs
								)
							)
						) .
						Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) .
						Html::hidden( 'wpActionToken', $action )
					)
				)
			);

		$this->getOutput()->addHTML( $form );

		return;
	}

	private function doSubmit() {

		$request = $this->getRequest();
		$do = $request->getVal( 'wpActionToken' );
		$selfTitle = $this->getPageTitle();
		$status_OK = true;

		$anzahl_counted  = (int)$this->mDBr->selectField( 'page', 'COUNT(*)', [ 'page_namespace' => NS_MAIN, 'page_is_redirect' => 0 ], __METHOD__ );
		$anzahl_stat_old = (int)$this->mDBr->selectField( 'site_stats', 'ss_good_articles', [ 'ss_row_id' => 1 ], __METHOD__ );

		if ( $anzahl_counted === $anzahl_stat_old ) {
			$this->success( 'refreshsitestatstable-status-ok-good', $anzahl_counted );
		} else {
			$this->mDBw->update( 'site_stats', [ 'ss_good_articles' => $anzahl_counted ], [ 'ss_row_id' => 1 ], __METHOD__, 'IGNORE' );
			$anzahl_stat_new = (int)$this->mDBr->selectField( 'site_stats', 'ss_good_articles', [ 'ss_row_id' => 1 ], __METHOD__ );
			if ( $anzahl_counted === $anzahl_stat_new ) {
				$this->success( 'refreshsitestatstable-inconsistent-good', $anzahl_stat_old, $anzahl_stat_new );
			} else {
				$status_OK = false;
			}
		}

		$anzahl_counted  = (int)$this->mDBr->selectField( 'page', 'COUNT(*)', [], __METHOD__ );
		$anzahl_stat_old = (int)$this->mDBr->selectField( 'site_stats', 'ss_total_pages', [ 'ss_row_id' => 1 ], __METHOD__ );

		if ( $anzahl_counted === $anzahl_stat_old ) {
			$this->success( 'refreshsitestatstable-status-ok-total', $anzahl_counted );
		} else {
			$this->mDBw->update( 'site_stats', [ 'ss_total_pages' => $anzahl_counted ], [ 'ss_row_id' => 1 ], __METHOD__, 'IGNORE' );
			$anzahl_stat_new = (int)$this->mDBr->selectField( 'site_stats', 'ss_total_pages', [ 'ss_row_id' => 1 ], __METHOD__ );
			if ( $anzahl_counted === $anzahl_stat_new ) {
				$this->success( 'refreshsitestatstable-inconsistent-total', $anzahl_stat_old, $anzahl_stat_new );
			} else {
				$status_OK = false;
			}
		}

		$anzahl_counted  = (int)$this->mDBr->selectField( 'image', 'COUNT(*)', [], __METHOD__ );
		$anzahl_stat_old = (int)$this->mDBr->selectField( 'site_stats', 'ss_images', [ 'ss_row_id' => 1 ], __METHOD__ );

		if ( $anzahl_counted === $anzahl_stat_old ) {
			$this->success( 'refreshsitestatstable-status-ok-images', $anzahl_counted );
		} else {
			$this->mDBw->update( 'site_stats', [ 'ss_images' => $anzahl_counted ], [ 'ss_row_id' => 1 ], __METHOD__, 'IGNORE' );
			$anzahl_stat_new = (int)$this->mDBr->selectField( 'site_stats', 'ss_images', [ 'ss_row_id' => 1 ], __METHOD__ );
			if ( $anzahl_counted === $anzahl_stat_new ) {
				$this->success( 'refreshsitestatstable-inconsistent-images', $anzahl_stat_old, $anzahl_stat_new );
			} else {
				$status_OK = false;
			}
		}

		$anzahl_counted  = (int)$this->mDBr->selectField( 'user', 'COUNT(*)', [], __METHOD__ );
		$anzahl_stat_old = (int)$this->mDBr->selectField( 'site_stats', 'ss_users', [ 'ss_row_id' => 1 ], __METHOD__ );

		if ( $anzahl_counted === $anzahl_stat_old ) {
			$this->success( 'refreshsitestatstable-status-ok-users', $anzahl_counted );
		} else {
			$this->mDBw->update( 'site_stats', [ 'ss_users' => $anzahl_counted ], [ 'ss_row_id' => 1 ], __METHOD__, 'IGNORE' );
			$anzahl_stat_new = (int)$this->mDBr->selectField( 'site_stats', 'ss_users', [ 'ss_row_id' => 1 ], __METHOD__ );
			if ( $anzahl_counted === $anzahl_stat_new ) {
				$this->success( 'refreshsitestatstable-inconsistent-users', $anzahl_stat_old, $anzahl_stat_new );
			} else {
				$status_OK = false;
			}
		}

		if ( !$status_OK ) {
			$this->error( 'refreshsitestatstable-status-error' );
			$this->showForm( $do );
		}

		return;
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		$msg = $this->msg( 'refreshsitestatstable-rights' );
		return version_compare( MW_VERSION, '1.41', '<' ) ? $msg->text() : $msg;
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * @inheritDoc
	 */
	public function isListed() {
		return $this->mAllowRefreshSiteStatsTable;
	}

	/**
	 * Output error message stuff :)
	 */
	private function error() {
		$args = func_get_args();
		$this->getOutput()->wrapWikiMsg( '<p class="' . $this->mErrorClass . '" style="display:block;">$1</p>', $args );
	}

	/**
	 * Output success message stuff :)
	 */
	private function success() {
		$args = func_get_args();
		$this->getOutput()->wrapWikiMsg( '<div class="' . $this->mSuccessClass . '">$1</div><br style="clear:both" />', $args );
	}
}
