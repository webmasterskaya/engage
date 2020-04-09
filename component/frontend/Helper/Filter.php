<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2020 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Engage\Site\Helper;

defined('_JEXEC') or die();

use FOF30\Container\Container;
use HTMLPurifier;
use HTMLPurifier_Config;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;

/**
 * Smart HTML text filter
 *
 * @package Akeeba\Engage\Site\Helper
 */
final class Filter
{
	/**
	 * A temporary instance of the component's container
	 *
	 * @var Container|null
	 */
	private static $container = null;

	/**
	 * Text filter mode: joomla, htmlpurifier
	 *
	 * @var string|null
	 */
	private static $filterMode = null;

	private static $joomlaFilterSettings = null;

	/**
	 * The static instance of HTML Purifier
	 *
	 * @var HTMLPurifier|null
	 */
	private static $purifier = null;

	/**
	 * Filters HTML content coming from the user. Returns a presumably safe string.
	 *
	 * @param   string|null  $text  The text to filter
	 *
	 * @return  string  The presumably safe, filtered text
	 */
	public static function filterText(?string $text): string
	{
		switch ($filterMode = self::getFilterMode())
		{
			default:
			case 'joomla':
				return ComponentHelper::filterText($text ?? '');

				break;

			case 'htmlpurifier':
			case 'strict':
				return self::purify($text ?? '', $filterMode === 'strict');

				break;
		}
	}

	/**
	 * Returns the component's container (temporary instance)
	 *
	 * @return  Container|null
	 */
	private static function getContainer(): Container
	{
		if (is_null(self::$container))
		{
			self::$container = Container::getInstance('com_engage', [
				'tempInstance' => true,
			]);
		}

		return self::$container;
	}

	/**
	 * Returns a component configuration parameters
	 *
	 * @param   string  $key      The configuration key to load
	 * @param   mixed   $default  Default value; NULL if omitted
	 *
	 * @return  mixed  The configuration parameter's value
	 */
	private static function getParam(string $key, $default = null)
	{
		$container = self::getContainer();

		return $container->params->get($key, $default);
	}

	/**
	 * Returns the configured filtering mode
	 *
	 * @return  string  'joomla' or 'htmlpurifier'
	 */
	private static function getFilterMode(): string
	{
		if (!is_null(self::$filterMode))
		{
			return self::$filterMode;
		}

		self::$filterMode = self::getParam('filter_mode', 'strict');

		if (!in_array(self::$filterMode, ['joomla', 'htmlpurifier', 'strict']))
		{
			self::$filterMode = 'strict';
		}

		return self::$filterMode;
	}

	/**
	 * Filters some text using HTML Purifier
	 *
	 * @param   string|null  $text  Potentially unsafe string
	 *
	 * @return  string  Presumably safe string
	 */
	private static function purify(?string $text, bool $strict = false): string
	{
		// Get Joomla filtering configuration
		$joomlaFiltering = self::getJoomlaFilterSettings();

		// No filtering: return the $text as-is
		if ($joomlaFiltering['filterType'] == 'none')
		{
			return $text;
		}

		/**
		 * No HTML
		 *
		 * Non-strict mode: use Joomla's default filtering
		 * Strict mode: use HTML Purifier with the default, restrictive whitelist
		 */
		if (($joomlaFiltering['filterType'] == 'noHTML') && !$strict)
		{
			return ComponentHelper::filterText($text);
		}

		return self::getHTMLPurifier()->purify($text);
	}

	/**
	 * Make sure HTML Purifier's classes can be loaded.
	 *
	 * @return  void
	 */
	private static function includeHTMLPurifier(): void
	{
		// Make sure HTML Purifier is already loaded
		if (class_exists('HTMLPurifier_Config') && class_exists('HTMLPurifier'))
		{
			return;
		}

		$container   = self::getContainer();
		$includeMode = self::getParam('htmlpurifier_include', 'composer');

		switch ($includeMode)
		{
			case 'composer':
			default:
				require_once $container->backEndPath . '/vendor/autoload.php';
				break;

			case 'auto':
				require_once $container->backEndPath . '/vendor/ezyang/htmlpurifier/library/HTMLPUrifier.auto.php';
				break;

			case 'all':
				require_once $container->backEndPath . '/vendor/ezyang/htmlpurifier/library/HTMLPUrifier.includes.php';
				break;
		}
	}

	private static function getHTMLPurifier(): HTMLPurifier
	{
		// Return the cached object if it exists
		if (!is_null(self::$purifier))
		{
			return self::$purifier;
		}

		self::includeHTMLPurifier();

		// Get the HTML purifier fallback configuration
		$defaultWhitelist = 'p,b,a[href],i,u,strong,em,small,big,span[style],font[size],font[color],ul,ol,li,br,img[src],img[width],img[height],code,pre,blockquote';
		$whitelist        = self::getParam('htmlpurifier_configstring', '');
		$whitelist        = trim($whitelist);

		if (empty($whitelist))
		{
			$whitelist = $defaultWhitelist;
		}

		// Set up HTML Purifier's configuration
		$config = HTMLPurifier_Config::createDefault();
		$config->set('Core', 'Encoding', 'UTF-8');
		$config->set('HTML', 'Doctype', 'HTML 4.01 Transitional');
		$config->set('Cache', 'SerializerPath', self::getCachePath());

		// If we are given an explicit configuration we will not go through Joomla's text filters configuration.
		if (self::getParam('htmlpurifier_config_joomla', 0) == 0)
		{
			$config->set('HTML', 'Allowed', $whitelist);
		}
		else
		{
			// Get Joomla filtering configuration
			$joomlaFiltering = self::getJoomlaFilterSettings();

			switch ($joomlaFiltering['filterType'])
			{
				case 'noHTML':
					$config->set('HTML', 'Allowed', $whitelist);
					break;

				case 'none':
					break;

				case 'blacklist':
					$config->set('HTML', 'ForbiddenElements', $joomlaFiltering['blacklistTags']);
					$config->set('HTML', 'ForbiddenAttributes', $joomlaFiltering['blacklistTags']);
					break;

				case 'whitelist':
					$config->set('HTML', 'AllowedElements', $joomlaFiltering['whitelistTags']);
					$config->set('HTML', 'AllowedAttributes', array_map(function ($x) {
						return '*.' . $x;
					}, $joomlaFiltering['whitelistTags']));
					break;
			}
		}

		self::$purifier = new HTMLPurifier($config);

		return self::$purifier;
	}

	/**
	 * Get the cache path for HTML Purifier to store serialized definitions
	 *
	 * @return string|null
	 */
	private static function getCachePath(): ?string
	{
		if (!is_writable(JPATH_CACHE))
		{
			return null;
		}

		$cachePath = JPATH_CACHE . '/' . self::getContainer()->componentName . '/htmlpurifier';

		if (@file_exists($cachePath) && !@is_dir($cachePath))
		{
			return null;
		}

		if (!@file_exists($cachePath))
		{
			if (!@mkdir($cachePath, 0755, true))
			{
				return null;
			}
		}

		if (!is_writeable($cachePath))
		{
			return null;
		}

		return $cachePath;
	}

	private static function getJoomlaFilterSettings(): array
	{
		if (!empty(self::$joomlaFilterSettings))
		{
			return self::$joomlaFilterSettings;
		}

		$filter = InputFilter::getInstance([], [], 1, 1);

		self::$joomlaFilterSettings = [
			'filterType'          => 'noHTML',
			'blacklistTags'       => $filter->tagBlacklist,
			'blacklistAttributes' => $filter->attrBlacklist,
			'whitelistTags'       => [],
			'whitelistAttributes' => [],
		];

		// Filter settings
		$config     = ComponentHelper::getParams('com_config');
		$user       = Factory::getUser();
		$userGroups = Access::getGroupsByUser($user->get('id'));

		$filters = $config->get('filters');

		$blackListTags        = [];
		$blackListAttributes  = [];
		$customListTags       = [];
		$customListAttributes = [];
		$whiteListTags        = [];
		$whiteListAttributes  = [];
		$whiteList            = false;
		$blackList            = false;
		$customList           = false;
		$unfiltered           = false;

		// Cycle through each of the user groups the user is in. Remember they are included in the Public group as well.
		foreach ($userGroups as $groupId)
		{
			// A group was added but Global Configuration was not saved. Therefore this group has no filters.
			if (!isset($filters->$groupId))
			{
				continue;
			}

			// Each group the user is in could have different filtering properties.
			$filterData = $filters->$groupId;
			$filterType = strtoupper($filterData->filter_type);

			// No HTML
			if ($filterType === 'NH')
			{
				continue;

			}

			// No filtering
			if ($filterType === 'NONE')
			{
				// No HTML filtering.
				$unfiltered = true;

				continue;
			}

			// Preprocess the tags and attributes for Blacklist or Whitelist
			$tags           = explode(',', $filterData->filter_tags);
			$attributes     = explode(',', $filterData->filter_attributes);
			$tempTags       = [];
			$tempAttributes = [];

			foreach ($tags as $tag)
			{
				$tag = trim($tag);

				if ($tag)
				{
					$tempTags[] = $tag;
				}
			}

			foreach ($attributes as $attribute)
			{
				$attribute = trim($attribute);

				if ($attribute)
				{
					$tempAttributes[] = $attribute;
				}
			}

			// Collect the blacklist or whitelist tags and attributes. Each list is cummulative.
			if ($filterType === 'BL')
			{
				$blackList           = true;
				$blackListTags       = array_merge($blackListTags, $tempTags);
				$blackListAttributes = array_merge($blackListAttributes, $tempAttributes);

				continue;
			}

			if ($filterType === 'CBL')
			{
				// Only set to true if Tags or Attributes were added
				if ($tempTags || $tempAttributes)
				{
					$customList           = true;
					$customListTags       = array_merge($customListTags, $tempTags);
					$customListAttributes = array_merge($customListAttributes, $tempAttributes);
				}

				continue;
			}

			if ($filterType === 'WL')
			{
				$whiteList           = true;
				$whiteListTags       = array_merge($whiteListTags, $tempTags);
				$whiteListAttributes = array_merge($whiteListAttributes, $tempAttributes);

				continue;
			}
		}

		// Remove duplicates before processing (because the blacklist uses both sets of arrays).
		$blackListTags        = array_unique($blackListTags);
		$blackListAttributes  = array_unique($blackListAttributes);
		$customListTags       = array_unique($customListTags);
		$customListAttributes = array_unique($customListAttributes);
		$whiteListTags        = array_unique($whiteListTags);
		$whiteListAttributes  = array_unique($whiteListAttributes);

		/**
		 * NO FILTERING
		 *
		 * Input will be returned as-is
		 */
		if ($unfiltered)
		{
			self::$joomlaFilterSettings['filterType'] = 'none';

			return self::$joomlaFilterSettings;
		}

		/**
		 * CUSTOM BLACKLIST
		 *
		 * All tags and attributes allowed except those explicitly excluded (blacklisted)
		 */
		if ($customList)
		{
			self::$joomlaFilterSettings['filterType'] = 'blacklist';

			// Override filter's default blacklist tags and attributes
			if ($customListTags)
			{
				self::$joomlaFilterSettings['blacklistTags'] = $customListTags;
			}

			if ($customListAttributes)
			{
				self::$joomlaFilterSettings['blacklistTags'] = $customListAttributes;
			}

			return self::$joomlaFilterSettings;
		}

		/**
		 * DEFAULT BLACKLIST
		 *
		 * Similar to Custom Blacklist **HOWEVER** explicitly whitelisted tags and attributes are removed from the
		 * blacklist.
		 */
		if ($blackList)
		{
			self::$joomlaFilterSettings['filterType'] = 'blacklist';

			// Remove the whitelisted tags and attributes from the black-list.
			$blackListTags       = array_diff($blackListTags, $whiteListTags);
			$blackListAttributes = array_diff($blackListAttributes, $whiteListAttributes);

			// Remove whitelisted tags from filter's default blacklist
			if ($whiteListTags)
			{
				self::$joomlaFilterSettings['blacklistTags'] = array_diff($blackListTags, $whiteListTags);
			}

			// Remove whitelisted attributes from filter's default blacklist
			if ($whiteListAttributes)
			{
				self::$joomlaFilterSettings['blacklistTags'] = array_diff($blackListAttributes, $whiteListAttributes);
			}

			return self::$joomlaFilterSettings;
		}

		/**
		 * WHITELIST
		 *
		 * Only explicitly allowed tags and attributes are allowed.
		 */
		if ($whiteList)
		{
			self::$joomlaFilterSettings['filterType']          = 'whitelist';
			self::$joomlaFilterSettings['whitelistTags']       = $whiteList;
			self::$joomlaFilterSettings['whitelistAttributes'] = $whiteListAttributes;

			return self::$joomlaFilterSettings;
		}

		/**
		 * NO HTML
		 *
		 * Strictest filtering level.
		 */
		self::$joomlaFilterSettings['filterType'] = 'noHTML';

		return self::$joomlaFilterSettings;
	}
}