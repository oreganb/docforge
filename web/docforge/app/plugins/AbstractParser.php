<?php

namespace DocForge\Plugins;

use DocForge\KnowledgeLayer\ParserPluginInterface;

abstract class AbstractParser implements ParserPluginInterface
{
    public function supportsKnowledgeLayer()
    {
        return '1.0';
    }

    public function cleanup()
    {
    }

    public function confidence()
    {
        return array('overall' => 85);
    }

    protected function block($type, $text, $level = null, $location = '')
    {
        $b = array('type' => $type, 'text' => $text, 'location' => $location);
        if ($level !== null) {
            $b['level'] = $level;
        }
        return $b;
    }
}
