<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_articles_mostpopular
 *
 * @copyright   Copyright (C) 2005 - 2012 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

require_once JPATH_SITE.'/components/com_content/helpers/route.php';

JModelLegacy::addIncludePath(JPATH_SITE.'/components/com_content/models', 'ContentModel');

/**
 * Helper for mod_articles_mostpopular
 *
 * @package     Joomla.Site
 * @subpackage  mod_articles_mostpopular
 */
abstract class modArticlesMostpopularHelper
{
  public static function getList(&$params)
  {
    // Create a new query object.
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
		
		
    // Select the required fields from the table.
    $query->select('a.id, a.title, a.alias, a.introtext, a.catid, a.created, a.created_by, a.created_by_alias, a.modified, a.modified_by, a.publish_up, a.publish_down, a.images, a.urls, a.attribs, a.access, p.1_day_stats, p.7_day_stats, p.30_day_stats, p.all_time_stats');
    $query->from('#__content AS a');
    $query->join('INNER','#__mostpopular AS p ON a.id=p.content_id');

    // Join over the categories.
    $query->select('c.title AS category_title, c.path AS category_route, c.access AS category_access, c.alias AS category_alias');
    $query->join('LEFT', '#__categories AS c ON c.id = a.catid');

    $query->select('parent.title as parent_title, parent.id as parent_id, parent.path as parent_route, parent.alias as parent_alias');
    $query->join('LEFT', '#__categories as parent ON parent.id = c.parent_id');


    // Filter by a single or group of categories (It does not include subcategories)
    $categoryId = $params->get('catid', array());

    if (is_numeric($categoryId)) {
      $categoryEquals = 'a.catid ='.(int) $categoryId;
      $query->where($categoryEquals);
    }
    elseif (is_array($categoryId) && (count($categoryId) > 0)) {
      JArrayHelper::toInteger($categoryId);
      $categoryId = implode(',', $categoryId);
      if (!empty($categoryId)) {
	$query->where('a.catid IN'.' ('.$categoryId.')');
      }
    }

    // Filter by access level
    $user	= JFactory::getUser();
    $groups	= implode(',', $user->getAuthorisedViewLevels());
    $query->where('a.access IN ('.$groups.')');
    $query->where('c.access IN ('.$groups.')');
		
    // Filter by published state
    $published = 1;

    // Filter by featured state
    $featured = $params->get('show_front', 1) == 1 ? 'show' : 'hide';
    switch ($featured)
      {
      case 'hide':
	$query->where('a.featured = 0');
	break;

      case 'only':
	$query->where('a.featured = 1');
	break;

      case 'show':
      default:
	// Normally we do not discriminate
	// between featured/unfeatured items.
	break;
      }

      // Filter by start and end dates.
    $nullDate	= $db->Quote($db->getNullDate());
    $nowDate	= $db->Quote(JFactory::getDate()->toSql());

    $query->where('(a.publish_up = '.$nullDate.' OR a.publish_up <= '.$nowDate.')');
    $query->where('(a.publish_down = '.$nullDate.' OR a.publish_down >= '.$nowDate.')');

    // Filter by language
    $languageFilter = $params->get('language_filter', 1);
    if ($languageFilter == 1) {
      $query->where('a.language in ('.$db->quote(JFactory::getLanguage()->getTag()).','.$db->quote('*').')');
      /* @todo filter by contact language */
    }
		
    // Filter by stats module
    $orderingRange = $params->get('ordering_range', 0);
    switch ($orderingRange)
      {
		
      case 1:
	$query->order('p.1_day_stats DESC');
	break;
      case 7:
	$query->order('p.7_day_stats DESC');
	break;
      case 30:
	$query->order('p.30_day_stats DESC');
	break;
      case 0:
      default:
	$query->order('p.all_time_stats DESC');
	break;
      }

    $articlesCount = $params->get('count', 5);		
    $db->setQuery($query,0,$articlesCount);
    $items = $db->loadObjectList();

    // Access filter
    $access = !JComponentHelper::getParams('com_content')->get('show_noauth');
    $authorised = JAccess::getAuthorisedViewLevels(JFactory::getUser()->get('id'));


    // Get Menu Parameters
    $app = JFactory::getApplication();
    $menuId = $app->getMenu()->getActive()->id;
    $menu =   &JSite::getMenu();
    $itempars =  $menu->getItem($menuId)->params;
    $menuParams = new JParameter($itempars);
    $menuParamsArray = $menuParams->toArray();
    $globalParams = JComponentHelper::getParams('com_content', true);


		
    foreach ($items as &$item) {
      $item->slug = $item->id.':'.$item->alias;
      $item->catslug = $item->catid.':'.$item->category_alias;


      // Get the article params
      $articleParams = new JRegistry;
      $articleParams->loadString($item->attribs);
      $item->alternative_readmore = $articleParams->get('alternative_readmore');
      $item->layout = $articleParams->get('layout');
      $item->params = $articleParams;
      $articleArray = array();

      foreach ($menuParamsArray as $key => $value)
      {
	      if ($value === 'use_article')
	      {
		      // If the article has a value, use it
		      if ($articleParams->get($key) != '')
		      {
			      // Get the value from the article
			      $articleArray[$key] = $articleParams->get($key);
		      }
		      else
		      {
			      // Otherwise, use the global value
			      $articleArray[$key] = $globalParams->get($key);
		      }
	      }
      }

      // Merge the selected article params
      if (count($articleArray) > 0)
      {
	      $articleParams = new JRegistry;
	      $articleParams->loadArray($articleArray);
	      $item->params->merge($articleParams);
      }     


      // get display date
      switch ($item->params->get('list_show_date'))
      {
	      case 'modified':
		      $item->displayDate = $item->modified;
		      break;

	      case 'published':
		      $item->displayDate = ($item->publish_up == 0) ? $item->created : $item->publish_up;
		      break;

	      default:
	      case 'created':
		      $item->displayDate = $item->created;
		      break;
      }


      if ($access || in_array($item->access, $authorised)) {
	// We know that user has the privilege to view the article
	$item->link = JRoute::_(ContentHelperRoute::getArticleRoute($item->slug, $item->catslug));
      } else {
	$item->link = JRoute::_('index.php?option=com_users&view=login');
      }
    }

    return $items;
  }
	
}
