<?php

/*
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * 
 */

class paginator {

    /**
     * The record number to start dislpaying from
     *
     * @access public
     * @var int
     */
    protected $limitstart = null;

    /**
     * Number of rows to display per page
     *
     * @access public
     * @var int
     */
    protected $limit = null;

    /**
     * Total number of rows
     *
     * @access public
     * @var int
     */
    protected $total = null;
    public $url = '';
    public $onclick = '';

    protected function set($property, $value = null) {
        $previous = isset($this->$property) ? $this->$property : null;
        $this->$property = $value;
        return $previous;
    }

    protected function get($property, $default = null) {
        if (isset($this->$property)) {
            return $this->$property;
        }
        return $default;
    }

    public function setUrl($url) {

        if (stripos($url, '?') !== false) {

            $this->url = $url . "&limitstart=%s&limit=%s";
        } else {

            $this->url = $url . "?limitstart=%s&limit=%s";
        }
    }

    public function start() {

        return (int) $this->limitstart;
    }

    public function end() {

        return (int) $this->limit;
    }

    /**
     * Constructor
     *
     * @param	int		The total number of items
     * @param	int		The offset of the item to start at
     * @param	int		The number of items to display per page
     */
    public function __construct($total, $limitstart = 0, $limit = 10) {

        $uri = new uri();

        $current = $uri->get('uri') . '?' . http_build_query($_GET, '', '&');

        $current = preg_replace(array("/(&|\?)limitstart=[0-9]*/i", "/(&|\?)limit=[0-9]*/i", '/%{1}/'), array('', '', '%%'), $uri->get('current'));

        if (strings::stripos($current, "?") !== false) {

            $current .="&limitstart=%s&limit=%s";
        } else {
            $current = (array) explode("&", $current);
            $current[0] .="?limitstart=%s&limit=%s";

            $current = implode('&', $current);
        }

        $this->url = $current;

        // Value/Type checking
        $this->total = (int) $total;
        $this->limitstart = (int) max($limitstart, 0);
        $this->limit = (int) max($limit, 0);

        if ($this->limit > $this->total) {
            $this->limitstart = 0;
        }

        if (!$this->limit) {
            $this->limit = $total;
            $this->limitstart = 0;
        }

        if ($this->limitstart > $this->total) {
            $this->limitstart -= $this->limitstart % $this->limit;
        }

        // Set the total pages and current page values
        if ($this->limit > 0) {
            $this->set('pages.total', ceil($this->total / $this->limit));
            $this->set('pages.current', ceil(($this->limitstart + 1) / $this->limit));
        }

        // Set the pagination iteration loop values
        $displayedPages = 10;
        $_remainder = $this->get('pages.current') % $displayedPages;
        if ($_remainder == 0) {
            $this->set('pages.start', (floor($this->get('pages.current') / $displayedPages)) * $displayedPages - 4);
        } elseif ($_remainder == 1 and $this->get('pages.current') > $displayedPages) {
            $this->set('pages.start', (floor(($this->get('pages.current') - 1) / $displayedPages)) * $displayedPages - 4);
        } else {
            $this->set('pages.start', (floor($this->get('pages.current') / $displayedPages)) * $displayedPages + 1);
        }


        if ($this->get('pages.start') + $displayedPages - 1 < $this->get('pages.total')) {
            $this->set('pages.stop', $this->get('pages.start') + $displayedPages - 1);
        } else {
            $this->set('pages.stop', $this->get('pages.total'));
        }
    }

    public function getRowOffset($index) {
        return $index + 1 + $this->limitstart;
    }

    public function getData() {
        static $data;
        if (!is_object($data)) {
            $data = $this->_buildDataObject();
        }
        return $data;
    }

    public function getPagesCounter() {
        // Initialize variables
        $html = null;
        if ($this->get('pages.total') > 1) {
            $html .= sprintf('%s of %s', $this->get('pages.current'), $this->get('pages.total'));
        }
        return $html;
    }

    public function getResultsCounter() {
        // Initialize variables
        $html = null;
        $fromResult = $this->limitstart + 1;

        // If the limit is reached before the end of the list
        if ($this->limitstart + $this->limit < $this->total) {
            $toResult = $this->limitstart + $this->limit;
        } else {
            $toResult = $this->total;
        }

        // If there are results found
        if ($this->total > 0) {
            $msg = sprintf('from %s to %s Results of %s', $fromResult, $toResult, $this->total);
            $html .= "\n" . $msg;
        } else {
            $html .= "\n" . 'No records found';
        }

        return $html;
    }

    public function getPagesLinks() {


        // Build the page navigation list
        $data = $this->_buildDataObject();

        $list = array();

        $itemOverride = false;
        $listOverride = false;

        $chromePath = 'pagination.php';


        // Build the select list
        if ($data->all->base !== null) {
            $list['all']['active'] = true;
            $list['all']['data'] = ($itemOverride) ? pagination_item_active($data->all) : $this->_item_active($data->all);
        } else {
            $list['all']['active'] = false;
            $list['all']['data'] = ($itemOverride) ? pagination_item_inactive($data->all) : $this->_item_inactive($data->all);
        }

        if ($data->start->base !== null) {
            $list['start']['active'] = true;
            $list['start']['data'] = ($itemOverride) ? pagination_item_active($data->start) : $this->_item_active($data->start);
        } else {
            $list['start']['active'] = false;
            $list['start']['data'] = ($itemOverride) ? pagination_item_inactive($data->start) : $this->_item_inactive($data->start);
        }
        if ($data->previous->base !== null) {
            $list['previous']['active'] = true;
            $list['previous']['data'] = ($itemOverride) ? pagination_item_active($data->previous) : $this->_item_active($data->previous);
        } else {
            $list['previous']['active'] = false;
            $list['previous']['data'] = ($itemOverride) ? pagination_item_inactive($data->previous) : $this->_item_inactive($data->previous);
        }

        $list['pages'] = array(); //make sure it exists
        foreach ($data->pages as $i => $page) {
            if ($page->base !== null) {
                $list['pages'][$i]['active'] = true;
                $list['pages'][$i]['data'] = ($itemOverride) ? pagination_item_active($page) : $this->_item_active($page);
            } else {
                $list['pages'][$i]['active'] = false;
                $list['pages'][$i]['data'] = ($itemOverride) ? pagination_item_inactive($page) : $this->_item_inactive($page);
            }
        }

        if ($data->next->base !== null) {
            $list['next']['active'] = true;
            $list['next']['data'] = ($itemOverride) ? pagination_item_active($data->next) : $this->_item_active($data->next);
        } else {
            $list['next']['active'] = false;
            $list['next']['data'] = ($itemOverride) ? pagination_item_inactive($data->next) : $this->_item_inactive($data->next);
        }
        if ($data->end->base !== null) {
            $list['end']['active'] = true;
            $list['end']['data'] = ($itemOverride) ? pagination_item_active($data->end) : $this->_item_active($data->end);
        } else {
            $list['end']['active'] = false;
            $list['end']['data'] = ($itemOverride) ? pagination_item_inactive($data->end) : $this->_item_inactive($data->end);
        }

        if ($this->total > $this->limit) {
            return ($listOverride) ? pagination_list_render($list) : $this->_list_render($list);
        } else {
            return '';
        }
    }

    public function getList() {
        // Initialize variables


        $html = $this->getPagesLinks();

        $html .= "\n<input type=\"hidden\" name=\"limitstart\" id='limitstart' value=\"" . $this->limitstart . "\" />";
        $html .= "\n<input type=\"hidden\" name=\"limit\" id='limit' value=\"" . $this->limit . "\" />";


        return $html;
    }

    protected function _list_render($list) {
        // Initialize variables
        $html = null;

        // Reverse output rendering for right-to-left display
        $html .= '&lt;&lt; ';
        $html .= $list['start']['data'];
        $html .= ' &lt; ';
        $html .= $list['previous']['data'];
        foreach ($list['pages'] as $page) {
            $html .= ' ' . $page['data'];
        }
        $html .= ' ' . $list['next']['data'];
        $html .= ' &gt;';
        $html .= ' ' . $list['end']['data'];
        $html .= ' &gt;&gt;';

        return $html;
    }

    protected function _item_active($item) {

        return "<a title=\"" . $item->text . "\" href=\"" . $item->link . "\" onclick=\"" . $item->onclick . "\" class=\"pagenav\">" . $item->text . "</a>";
    }

    protected function _item_inactive($item) {

        return "<span class=\"pagenav\">" . $item->text . "</span>";
    }

    protected function _buildDataObject() {
        // Initialize variables
        $data = new stdClass();

        $data->all = new JPaginationObject('View All');
        if (isset($this->_viewall) && !$this->_viewall) {
            $data->all->base = '0';

            $data->all->link = Route::get(sprintf($this->url, "0", $this->limit));
        }

        // Set the start and previous data objects
        $data->start = new JPaginationObject('Start');
        $data->previous = new JPaginationObject('Prev');

        if ($this->get('pages.current') > 1) {
            $page = ($this->get('pages.current') - 2) * $this->limit;

            $page = $page == 0 ? '' : $page; //set the empty for removal from route

            $data->start->base = '0';
            $data->start->link = Route::get(sprintf($this->url, "0", $this->limit));
            $data->previous->base = $page;
            $data->previous->link = Route::get(sprintf($this->url, $page, $this->limit));

            if ($this->onclick) {
                $data->start->onclick = sprintf($this->onclick, 0, $this->limit);
            }

            if ($this->onclick) {
                $data->previous->onclick = sprintf($this->onclick, ($this->get('pages.current') - 2), $this->limit);
            }
        }

        // Set the next and end data objects
        $data->next = new JPaginationObject('Next');
        $data->end = new JPaginationObject('End');

        if ($this->get('pages.current') < $this->get('pages.total')) {
            $next = $this->get('pages.current') * $this->limit;
            $end = ($this->get('pages.total') - 1) * $this->limit;

            $data->next->base = $next;
            $data->next->link = Route::get(sprintf($this->url, $next, $this->limit));
            if ($this->onclick) {
                $data->next->onclick = sprintf($this->onclick, $next, $this->limit);
            }


            $data->end->base = $end;
            $data->end->link = Route::get(sprintf($this->url, $end, $this->limit));
            if ($this->onclick) {
                $data->end->onclick = sprintf($this->onclick, $end, $this->limit);
            }
        }

        $data->pages = array();
        $stop = $this->get('pages.stop');
        for ($i = $this->get('pages.start'); $i <= $stop; $i++) {
            $offset = ($i - 1) * $this->limit;

            $offset = $offset == 0 ? '' : $offset;  //set the empty for removal from route

            $data->pages[$i] = new JPaginationObject($i);
            if ($i != $this->get('pages.current') || (isset($this->_viewall) && $this->_viewall)) {
                $data->pages[$i]->base = $offset;
                $data->pages[$i]->link = Route::get(sprintf($this->url, $offset, $this->limit));
                if ($this->onclick) {
                    $data->pages[$i]->onclick = sprintf($this->onclick, ($i - 1) * $this->limit, $this->limit);
                }
            } elseif ($i == $this->get('pages.current')) {
                $data->pages[$i]->text = '<b>' . $i . '</b>';
            }
        }
        return $data;
    }

}

/**
 * Pagination object representing a particular item in the pagination lists
 *

 * @subpackage	HTML

 */
class JPaginationObject {

    public $text;
    public $base;
    public $link;
    public $onclick;

    function __construct($text, $base = null, $link = null, $onclick = null) {
        $this->text = $text;
        $this->base = $base;
        $this->link = $link;
        $this->onclick = $onclick;
    }

}
