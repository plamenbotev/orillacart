<?php

/*
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * 
 */

class RegistryFormatXML extends RegistryFormat {

    public function objectToString($object, $options = array()) {
        // Initialise variables.
        $rootName = (isset($options['name'])) ? $options['name'] : 'registry';
        $nodeName = (isset($options['nodeName'])) ? $options['nodeName'] : 'node';

        // Create the root node.
        $root = simplexml_load_string('<' . $rootName . ' />');

        // Iterate over the object members.
        $this->getXmlChildren($root, $object, $nodeName);

        return $root->asXML();
    }

    public function stringToObject($data, $options = array()) {
        // Initialize variables.
        $obj = new stdClass;

        // Parse the XML string.
        $xml = simplexml_load_string($data);

        foreach ($xml->children() as $node) {
            $obj->$node['name'] = $this->getValueFromNode($node);
        }

        return $obj;
    }

    protected function getValueFromNode($node) {
        switch ($node['type']) {
            case 'integer':
                $value = (string) $node;
                return (int) $value;
                break;
            case 'string':
                return (string) $node;
                break;
            case 'boolean':
                $value = (string) $node;
                return (bool) $value;
                break;
            case 'double':
                $value = (string) $node;
                return (float) $value;
                break;
            case 'array':
                $value = array();
                foreach ($node->children() as $child) {
                    $value[(string) $child['name']] = $this->getValueFromNode($child);
                }
                break;
            default:
                $value = new stdClass;
                foreach ($node->children() as $child) {
                    $value->$child['name'] = $this->getValueFromNode($child);
                }
                break;
        }

        return $value;
    }

    protected function getXmlChildren(&$node, $var, $nodeName) {
        // Iterate over the object members.
        foreach ((array) $var as $k => $v) {
            if (is_scalar($v)) {
                $n = $node->addChild($nodeName, $v);
                $n->addAttribute('name', $k);
                $n->addAttribute('type', gettype($v));
            } else {
                $n = $node->addChild($nodeName);
                $n->addAttribute('name', $k);
                $n->addAttribute('type', gettype($v));

                $this->getXmlChildren($n, $v, $nodeName);
            }
        }
    }

}
