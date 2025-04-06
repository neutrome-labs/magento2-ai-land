<?php
/**
 * NeutromeLabs AiLand Theme Service
 *
 * Provides helper methods for theme-related operations like fetching configurations.
 *
 * @category    NeutromeLabs
 * @package     NeutromeLabs_AiLand
 * @author      Cline (AI Assistant)
 */
declare(strict_types=1);

namespace NeutromeLabs\AiLand\Model\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory as ThemeCollectionFactory;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\View\DesignInterface;

/**
 * Service class for theme-related operations.
 */
class ThemeService
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ThemeCollectionFactory
     */
    private $themeCollectionFactory;

    /**
     * @var ComponentRegistrarInterface
     */
    private $componentRegistrar;

    /**
     * @var FileDriver
     */
    private $fileDriver;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ThemeCollectionFactory $themeCollectionFactory
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param FileDriver $fileDriver
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ThemeCollectionFactory $themeCollectionFactory,
        ComponentRegistrarInterface $componentRegistrar,
        FileDriver $fileDriver,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->themeCollectionFactory = $themeCollectionFactory;
        $this->componentRegistrar = $componentRegistrar;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
    }

    /**
     * Get Tailwind config content for the given store's theme.
     *
     * @param int $storeId
     * @return string|null
     */
    public function getTailwindConfig(int $storeId): ?string
    {
        try {
            $themeId = $this->scopeConfig->getValue(
                DesignInterface::XML_PATH_THEME_ID,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            if (!$themeId) {
                $this->logger->info('No theme ID configured for store.', ['store_id' => $storeId]);
                return null;
            }

            $themeCollection = $this->themeCollectionFactory->create();
            $theme = $themeCollection->getItemById((int)$themeId);

            if (!$theme || !$theme->getId()) {
                $this->logger->warning('Could not load theme model for configured ID.', ['theme_id' => $themeId, 'store_id' => $storeId]);
                return null;
            }

            $themePathIdentifier = $theme->getFullPath();
            if (!$themePathIdentifier) {
                 $this->logger->warning('Theme model does not have a path identifier.', ['theme_id' => $themeId]);
                 return null;
            }
            // Ensure component type is correct
            $themeDir = $this->componentRegistrar->getPath(ComponentRegistrar::THEME, $themePathIdentifier);

            if (!$themeDir) {
                $this->logger->warning('Could not resolve theme directory path using ComponentRegistrar.', ['theme_path_id' => $themePathIdentifier, 'store_id' => $storeId]);
                return null;
            }

            $tailwindConfigPath = $themeDir . '/web/tailwind/tailwind.config.js';
            $tailwindConfigRelPath = 'web/tailwind/tailwind.config.js'; // For logging

            if ($this->fileDriver->isExists($tailwindConfigPath) && $this->fileDriver->isReadable($tailwindConfigPath)) {
                $this->logger->info('Found Tailwind config for theme.', ['path' => $tailwindConfigPath, 'store_id' => $storeId]);
                return $this->fileDriver->fileGetContents($tailwindConfigPath);
            } else {
                $this->logger->info('Tailwind config not found or not readable for theme.', [
                    'expected_relative_path' => $tailwindConfigRelPath,
                    'resolved_path' => $tailwindConfigPath ?: 'Not Resolved',
                    'theme_path' => $themeDir,
                    'store_id' => $storeId
                ]);
                return null;
            }
        } catch (LocalizedException $e) {
            $this->logger->error('Error resolving theme or Tailwind config path: ' . $e->getMessage(), ['store_id' => $storeId]);
        } catch (FileSystemException $e) {
            $this->logger->error('Filesystem error reading Tailwind config: ' . $e->getMessage(), ['store_id' => $storeId]);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error getting Tailwind config: ' . $e->getMessage(), ['store_id' => $storeId, 'exception' => $e]);
        }

        return null;
    }
}
