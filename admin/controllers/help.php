<?php
/**
 * @package    hubzero-cms
 * @copyright  Copyright (c) 2005-2020 The Regents of the University of California.
 * @license    http://opensource.org/licenses/MIT MIT
 */

namespace Components\Help\Admin\Controllers;

use Hubzero\Component\AdminController;
use Components\Help\Helpers\Finder;
use Request;
use Route;
use Lang;
use User;
use App;

/**
 * Help controller class
 */
class Help extends AdminController
{
	/**
	 * Display Help Article Pages
	 *
	 * @return  void
	 */
	public function displayTask()
	{
		// Get the page we are trying to access
		$page      = Request::getWord('page', '');
		$component = Request::getWord('component', 'com_help');
		$extension = Request::getWord('extension', '');

		if ($component == $this->_option && !Request::getString('tmpl', '') && !$page)
		{
			$this->view
				->set('components', self::getComponents())
				->setLayout('overview')
				->display();
			return;
		}

		$page = $page ?: 'index';

		// Force help template
		Request::setVar('tmpl', 'help');

		$finalHelpPage = Finder::page($component, $extension, $page);

		// Var to hold content
		$this->view->content = '';

		// If we have an existing pge
		if ($finalHelpPage != '')
		{
			ob_start();
			require_once $finalHelpPage;
			$this->view->content = ob_get_contents();
			ob_end_clean();
		}
		else if (isset($component) && $component != '' && $page == 'index')
		{
			// Get list of component pages
			$pages[] = Finder::pages($component);

			// Display page
			$this->view->content = $this->displayHelpPageIndexForPages($pages, 'h1');
		}
		else
		{
			// Raise error to avoid security bug
			App::abort(404, Lang::txt('COM_HELP_PAGE_NOT_FOUND'));
		}

		// Set vars for views
		$this->view->modified  = filemtime($finalHelpPage);
		$this->view->component = $component;
		$this->view->extension = $extension;
		$this->view->page      = $page;

		// Display
		$this->view->display();
	}

	/**
	 * Get array of components
	 *
	 * @param   boolean  $authCheck
	 * @return  array
	 * @since   1.3.2
	 */
	public static function getComponents($authCheck = true)
	{
		// Initialise variables.
		$lang   = Lang::getRoot();
		$db     = App::get('db');
		$query  = $db->getQuery();
		$result = array();
		$langs  = array();

		// Prepare the query.
		$query->select('id, title, alias, link, parent_id, img, element');
		$query->from('#__menu');

		// Filter on the enabled states.
		$query->leftJoin('#__extensions' ,'component_id', 'extension_id');
		$query->where('#__menu.client_id', '=', 1);
		$query->where('enabled', '=', 1);
		$query->where('id', '>', 1);

		// Order by lft.
		$query->order('lft','asc');

		$db->setQuery($query);
		// component list
		$components	= $db->loadObjectList();

		// Parse the list of extensions.
		foreach ($components as &$component)
		{
			// Trim the menu link.
			$component->link = trim($component->link);

			if ($component->parent_id == 1)
			{
				// Only add this top level if it is authorised and enabled.
				if ($authCheck == false || ($authCheck && User::authorise('core.manage', $component->element)))
				{
					// Root level.
					$result[$component->id] = $component;
					if (!isset($result[$component->id]->submenu))
					{
						$result[$component->id]->submenu = array();
					}

					// If the root menu link is empty, add it in.
					if (empty($component->link))
					{
						$component->link = 'index.php?option=' . $component->element;
					}

					if (!empty($component->element))
					{
						// Load the core file then
						// Load extension-local file.
						$lang->load($component->element . '.sys', PATH_APP . '/bootstrap/admin', null, false, false)
						|| $lang->load($component->element . '.sys', PATH_CORE . '/components/' . $component->element . '/admin', null, false, false)
						|| $lang->load($component->element . '.sys', PATH_APP . '/bootstrap/admin', $lang->getDefault(), false, false)
						|| $lang->load($component->element . '.sys', PATH_CORE . '/components/' . $component->element . '/admin', $lang->getDefault(), false, false);
					}
					$component->text = $lang->hasKey($component->title) ? Lang::txt($component->title) : $component->alias;
				}
			}
			else
			{
				// Sub-menu level.
				if (isset($result[$component->parent_id]))
				{
					// Add the submenu link if it is defined.
					if (isset($result[$component->parent_id]->submenu) && !empty($component->link))
					{
						$component->text = $lang->hasKey($component->title) ? Lang::txt($component->title) : $component->alias;
						$result[$component->parent_id]->submenu[] = &$component;
					}
				}
			}
		}

		return \Hubzero\Utility\Arr::sortObjects($result, 'text', 1, true, $lang->getLocale());
	}

	/**
	 * Get array of help pages for component
	 *
	 * @param   string  $component  Component to get pages for
	 * @return  array
	 */
	private function helpPagesForComponent($component)
	{
		// Get component name from database
		$sql = "SELECT `name` FROM `#__extensions` WHERE `type`=" . $this->database->quote('component') . " AND `element`=" . $this->database->quote($component) . " AND `enabled`=1";
		$this->database->setQuery($sql);
		$name = $this->database->loadResult();

		// Make sure we have a component
		if ($name == '')
		{
			return array();
		}

		// Path to help pages
		$path  = \Component::path($component) . DS . 'admin' . DS . 'help' . DS . Lang::getTag();

		// Make sure directory exists
		$pages = array();

		// Get help pages for this component
		$pages = \Filesystem::files($path, '.phtml');
		$pages = array_map(function($file)
		{
			return ltrim($file, DS);
		}, $pages);

		// Return pages
		return array(
			'name'   => $name,
			'option' => $component,
			'pages'  => $pages
		);
	}

	/**
	 * Get array of help pages for component
	 *
	 * @param   array   $componentAndPages  Component info and corresponding help pages
	 * @param   string  $headingLevel       Leading level for component separation
	 * @return  array
	 */
	private function displayHelpPageIndexForPages($componentAndPages, $headingLevel = 'h1')
	{
		// Var to hold content
		$content = '';

		// Loop through each component and pages group passed in
		foreach ($componentAndPages as $component)
		{
			$name = ucfirst(str_replace('com_', '', $component['name']));

			// Build content to return
			$content .= '<' . $headingLevel . '>' . Lang::txt('COM_HELP_COMPONENT_HELP', $name) . '</' . $headingLevel . '>';

			// Make sure we have pages
			if (count($component['pages']) > 0)
			{
				$content .= '<p>' . Lang::txt('COM_HELP_PAGE_INDEX_EXPLANATION', $name) . '</p>';
				$content .= '<ul>';
				foreach ($component['pages'] as $page)
				{
					$name = str_replace('.phtml', '', $page);
					$url  = Route::url('index.php?option=com_help&component=' . $component['option'] . '&page=' . $name);

					$content .= '<li><a href="' . $url . '">' . ucwords(str_replace('_', ' ', $name)) . '</a></li>';
				}
				$content .= '</ul>';
			}
			else
			{
				$content .= '<p>' . Lang::txt('COM_HELP_NO_PAGES_FOUND') . '</p>';
			}
		}

		return $content;
	}
}
