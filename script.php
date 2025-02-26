<?php defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Filesystem\Path;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Version;

class plgSystemBviInstallerScript
{
	/**
	 * Minimum PHP version required to install the extension.
	 *
	 * @var  string
	 *
	 * @since  1.0.0
	 */
	protected $minimumPhp = '7.0';

	/**
	 * Minimum Joomla version required to install the extension.
	 *
	 * @var  string
	 *
	 * @since  1.0.0
	 */
	protected $minimumJoomla = '3.9.0';

	/**
	 * Extension params for check.
	 *
	 * @var  array
	 *
	 * @since  1.0.0
	 */
	protected $extensionParams
		= array(
			'param' => 'value',
		);

	/**
	 * Runs right before any installation action.
	 *
	 * @param   string                           $type    Type of PostFlight action.
	 * @param   InstallerAdapter|PackageAdapter  $parent  Parent object calling object.
	 *
	 * @throws  Exception
	 *
	 * @return  boolean True on success, false on failure.
	 *
	 * @since  1.0.0
	 */
	function preflight($type, $parent)
	{
		// Check compatible
		if (!$this->checkCompatible('PLG_SYSTEM_BVI_'))
		{
			return false;
		}

		return true;
	}

	/**
	 * Method to check compatible.
	 *
	 * @param   string  $prefix  Language constants prefix.
	 *
	 * @throws  Exception
	 *
	 * @return  boolean True on success, false on failure.
	 *
	 * @since  1.0.0
	 */
	protected function checkCompatible($prefix = null)
	{
		// Check old Joomla
		if (!class_exists('Joomla\CMS\Version'))
		{
			JFactory::getApplication()->enqueueMessage(
				JText::sprintf(
					$prefix . 'ERROR_COMPATIBLE_JOOMLA',
					$this->minimumJoomla
				), 'error'
			);

			return false;
		}

		$app = Factory::getApplication();

		// Check PHP
		if (!(version_compare(PHP_VERSION, $this->minimumPhp) >= 0))
		{
			$app->enqueueMessage(
				Text::sprintf(
					$prefix . 'ERROR_COMPATIBLE_PHP', $this->minimumPhp
				),
				'error'
			);

			return false;
		}

		// Check joomla version
		if (!(new Version())->isCompatible($this->minimumJoomla))
		{
			$app->enqueueMessage(
				Text::sprintf(
					$prefix . 'ERROR_COMPATIBLE_JOOMLA', $this->minimumJoomla
				),
				'error'
			);

			return false;
		}

		return true;
	}

	/**
	 * Runs right after any installation action.
	 *
	 * @param   string            $type    Type of PostFlight action. Possible values are:
	 * @param   InstallerAdapter  $parent  Parent object calling object.
	 *
	 * @throws  Exception
	 *
	 * @return  boolean True on success, false on failure.
	 *
	 * @since  1.0.0
	 */
	function postflight($type, $parent)
	{
		// Enable plugin
		if ($type == 'install')
		{
			$this->enablePlugin($parent);
		}

		// Parse layouts
		$this->parseLayouts(
			$parent->getParent()->getManifest()->layouts, $parent->getParent()
		);

		// Refresh media
		if ($type === 'update')
		{
			(new Version())->refreshMediaVersion();
		}

		return true;
	}

	/**
	 * Enable plugin after installation.
	 *
	 * @param   InstallerAdapter  $parent  Parent object calling object.
	 *
	 * @since  1.0.0
	 */
	protected function enablePlugin($parent)
	{
		// Prepare plugin object
		$plugin          = new stdClass();
		$plugin->type    = 'plugin';
		$plugin->element = $parent->getElement();
		$plugin->folder  = (string) $parent->getParent()->manifest->attributes(
		)['group'];
		$plugin->enabled = 1;

		// Update record
		Factory::getDbo()->updateObject(
			'#__extensions', $plugin, array('type', 'element', 'folder')
		);
	}

	/**
	 * Method to parse through a layout element of the installation manifest and take appropriate action.
	 *
	 * @param   SimpleXMLElement  $element    The XML node to process.
	 * @param   Installer         $installer  Installer calling object.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since  1.0.0
	 */
	public function parseLayouts(SimpleXMLElement $element, $installer)
	{
		if (!$element || !count($element->children()))
		{
			return false;
		}

		// Get destination
		$folder      = ((string) $element->attributes()->destination) ? '/'
			. $element->attributes()->destination : null;
		$destination = Path::clean(JPATH_ROOT . '/layouts' . $folder);

		// Get source
		$folder = (string) $element->attributes()->folder;
		$source = ($folder
			&& file_exists(
				$installer->getPath('source') . '/' . $folder
			))
			?
			$installer->getPath('source') . '/' . $folder
			: $installer->getPath(
				'source'
			);

		// Prepare files
		$copyFiles = array();
		foreach ($element->children() as $file)
		{
			$path['src']  = Path::clean($source . '/' . $file);
			$path['dest'] = Path::clean($destination . '/' . $file);

			// Is this path a file or folder?
			$path['type'] = $file->getName() === 'folder' ? 'folder' : 'file';
			if (basename($path['dest']) !== $path['dest'])
			{
				$newdir = dirname($path['dest']);
				if (!Folder::create($newdir))
				{
					Log::add(
						Text::sprintf(
							'JLIB_INSTALLER_ERROR_CREATE_DIRECTORY', $newdir
						), Log::WARNING, 'jerror'
					);

					return false;
				}
			}

			$copyFiles[] = $path;
		}

		return $installer->copyFiles($copyFiles, true);
	}

	/**
	 * This method is called after extension is uninstalled.
	 *
	 * @param   InstallerAdapter  $parent  Parent object calling object.
	 *
	 * @since  1.0.0
	 */
	public function uninstall($parent)
	{
		// Remove layouts
		$this->removeLayouts($parent->getParent()->getManifest()->layouts);
	}

	/**
	 * Method to parse through a layouts element of the installation manifest and remove the files that were installed.
	 *
	 * @param   SimpleXMLElement  $element  The XML node to process.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since  1.0.0
	 */
	protected function removeLayouts(SimpleXMLElement $element)
	{
		if (!$element || !count($element->children()))
		{
			return false;
		}

		// Get the array of file nodes to process
		$files = $element->children();

		// Get source
		$folder = ((string) $element->attributes()->destination) ? '/'
			. $element->attributes()->destination : null;
		$source = Path::clean(JPATH_ROOT . '/layouts' . $folder);

		// Process each file in the $files array (children of $tagName).
		foreach ($files as $file)
		{
			$path = Path::clean($source . '/' . $file);

			// Actually delete the files/folders
			if (is_dir($path))
			{
				$val = Folder::delete($path);
			}
			else
			{
				$val = File::delete($path);
			}

			if ($val === false)
			{
				Log::add('Failed to delete ' . $path, Log::WARNING, 'jerror');

				return false;
			}
		}

		if (!empty($folder))
		{
			Folder::delete($source);
		}

		return true;
	}
}
