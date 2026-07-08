<?php

namespace DocForge\KnowledgeLayer;

/**
 * Analysis module contract (PRD §2.3).
 */
interface AnalysisModuleInterface
{
    /** @param array<string,mixed> $ir */
    public function applies(array $ir);

    /** @param array<string,mixed> $ir @return array<string,mixed> */
    public function analyse(array $ir);

    /** @return int 0-100 */
    public function confidence();

    /** @return array<string,mixed> */
    public function provenance();
}
