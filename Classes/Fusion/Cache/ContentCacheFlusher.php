<?php
namespace Neos\Neos\Fusion\Cache;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Service\AssetService;
use Neos\Neos\Domain\Model\Dto\AssetUsageInNodeProperties;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Fusion\Core\Cache\ContentCache;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * This service flushes Fusion content caches triggered by node changes.
 *
 * The method registerNodeChange() is triggered by a signal which is configured in the Package class of the Neos.Neos
 * package (this package). Information on changed nodes is collected by this method and the respective Fusion content
 * cache entries are flushed in one operation during Flow's shutdown procedure.
 *
 * @Flow\Scope("singleton")
 */
class ContentCacheFlusher
{
    /**
     * @Flow\Inject
     * @var ContentCache
     */
    protected $contentCache;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @var array
     */
    protected $tagsToFlush = array();

    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @var ContentContext[]
     */
    protected $contexts = [];

    /**
     * Register a node change for a later cache flush. This method is triggered by a signal sent via ContentRepository's Node
     * model or the Neos Publishing Service.
     *
     * @param NodeInterface $node The node which has changed in some way
     * @return void
     */
    public function registerNodeChange(NodeInterface $node)
    {
        $this->tagsToFlush[ContentCache::TAG_EVERYTHING] = 'which were tagged with "Everything".';

        $this->registerChangeOnNodeType($node->getNodeType()->getName(), $node->getIdentifier());
        $this->registerChangeOnNodeIdentifier($node->getIdentifier());

        $originalNode = $node;
        while ($node->getDepth() > 1) {
            $node = $node->getParent();
            // Workaround for issue #56566 in Neos.ContentRepository
            if ($node === null) {
                break;
            }
            $tagName = 'DescendantOf_' . $node->getIdentifier();
            $this->tagsToFlush[$tagName] = sprintf('which were tagged with "%s" because node "%s" has changed.', $tagName, $originalNode->getPath());
        }
    }

    /**
     * @param string $nodeIdentifier
     */
    public function registerChangeOnNodeIdentifier($nodeIdentifier)
    {
        $this->tagsToFlush[ContentCache::TAG_EVERYTHING] = 'which were tagged with "Everything".';
        $this->tagsToFlush['Node_' . $nodeIdentifier] = sprintf('which were tagged with "Node_%s" because that identifier has changed.', $nodeIdentifier);

        // Note, as we don't have a node here we cannot go up the structure.
        $tagName = 'DescendantOf_' . $nodeIdentifier;
        $this->tagsToFlush[$tagName] = sprintf('which were tagged with "%s" because node "%s" has changed.', $tagName, $nodeIdentifier);
    }

    /**
     * @param string $nodeTypeName
     * @param string $referenceNodeIdentifier
     */
    public function registerChangeOnNodeType($nodeTypeName, $referenceNodeIdentifier = null)
    {
        $this->tagsToFlush[ContentCache::TAG_EVERYTHING] = 'which were tagged with "Everything".';

        $nodeTypesToFlush = $this->getAllImplementedNodeTypeNames($this->nodeTypeManager->getNodeType($nodeTypeName));
        foreach ($nodeTypesToFlush as $nodeTypeNameToFlush) {
            $this->tagsToFlush['NodeType_' . $nodeTypeNameToFlush] = sprintf('which were tagged with "NodeType_%s" because node "%s" has changed and was of type "%s".', $nodeTypeNameToFlush, ($referenceNodeIdentifier ? $referenceNodeIdentifier : ''), $nodeTypeName);
        }
    }

    /**
     * Deprecated. Please use ContentCacheFlush::registerAssetChange
     *
     * @deprecated
     * @param AssetInterface $asset
     * @return void
     */
    public function registerAssetResourceChange(AssetInterface $asset)
    {
        $this->registerAssetChange($asset);
    }

    /**
     * Fetches possible usages of the asset and registers nodes that use the asset as changed.
     *
     * @param AssetInterface $asset
     * @return void
     */
    public function registerAssetChange(AssetInterface $asset)
    {
        if (!$asset->isInUse()) {
            return;
        }

        foreach ($this->assetService->getUsageReferences($asset) as $reference) {
            if (!$reference instanceof AssetUsageInNodeProperties) {
                continue;
            }

            $node = $this->getContextForReference($reference)->getNodeByIdentifier($reference->getNodeIdentifier());
            $this->registerNodeChange($node);

            $this->registerChangeOnNodeType($reference->getNodeTypeName(), $reference->getNodeIdentifier());

            $assetIdentifier = $this->persistenceManager->getIdentifierByObject($asset);
            $tagName = 'AssetDynamicTag_' . $assetIdentifier;
            $this->tagsToFlush[$tagName] = sprintf('which were tagged with "%s" because asset "%s" has changed.', $tagName, $assetIdentifier);
        }
    }

    /**
     * Flush caches according to the previously registered node changes.
     *
     * @return void
     */
    public function shutdownObject()
    {
        if ($this->tagsToFlush !== array()) {
            foreach ($this->tagsToFlush as $tag => $logMessage) {
                $affectedEntries = $this->contentCache->flushByTag($tag);
                if ($affectedEntries > 0) {
                    $this->systemLogger->log(sprintf('Content cache: Removed %s entries %s', $affectedEntries, $logMessage), LOG_DEBUG);
                }
            }
        }
    }

    /**
     * @param AssetUsageInNodeProperties $assetUsage
     * @return ContentContext
     */
    protected function getContextForReference(AssetUsageInNodeProperties $assetUsage)
    {
        $hash = md5(sprintf('%s-%s', $assetUsage->getWorkspaceName(), json_encode($assetUsage->getDimensionValues())));
        if (!isset($this->contexts[$hash])) {
            $this->contexts[$hash] = $this->contextFactory->create([
                'workspaceName' => $assetUsage->getWorkspaceName(),
                'dimensions' => $assetUsage->getDimensionValues(),
                'invisibleContentShown' => true,
                'inaccessibleContentShown' => true
            ]);
        }

        return $this->contexts[$hash];
    }

    /**
     * @param NodeType $nodeType
     * @return array<string>
     */
    protected function getAllImplementedNodeTypeNames(NodeType $nodeType)
    {
        $self = $this;
        $types = array_reduce($nodeType->getDeclaredSuperTypes(), function (array $types, NodeType $superType) use ($self) {
            return array_merge($types, $self->getAllImplementedNodeTypeNames($superType));
        }, [$nodeType->getName()]);

        $types = array_unique($types);
        return $types;
    }
}
