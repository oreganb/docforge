<?php

namespace DocForge\Analysis;

use DocForge\KnowledgeLayer\AnalysisModuleInterface;

abstract class AbstractModule implements AnalysisModuleInterface
{
    public function confidence()
    {
        return 75;
    }

    public function provenance()
    {
        return array(
            'method' => 'extractive',
            'tool' => static::toolName(),
            'fallback' => false,
        );
    }

    /** @return string */
    abstract protected static function toolName();
}
