<?php

namespace DocForge\KnowledgeLayer;

/**
 * Parser plugin contract (PRD §2.3).
 */
interface ParserPluginInterface
{
    public function detect($bytes, $mime);

    /** @return array<string,mixed> */
    public function extract($filePath);

    /** @return array<string,mixed> */
    public function metadata($filePath);

    /** @return array<string,mixed> */
    public function confidence();

    public function cleanup();

    /** v5.1 amendment 2 */
    public function supportsKnowledgeLayer();
}
