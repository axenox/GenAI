<?php
namespace axenox\GenAI;

use axenox\GenAI\Facades\AiChatFacade;
use exface\Core\Interfaces\InstallerInterface;
use exface\Core\CommonLogic\Model\App;
use exface\Core\CommonLogic\AppInstallers\AbstractSqlDatabaseInstaller;
use exface\Core\Facades\AbstractHttpFacade\HttpFacadeInstaller;
use exface\Core\Factories\FacadeFactory;

class GenAIApp extends App
{
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\App::getInstaller()
     */
    public function getInstaller(InstallerInterface $injected_installer = null)
    {
        $installer = parent::getInstaller($injected_installer);
        
        // AI chat facade
        $tplInstaller = new HttpFacadeInstaller($this->getSelector());
        $tplInstaller->setFacade(FacadeFactory::createFromString(AiChatFacade::class, $this->getWorkbench()));
        $installer->addInstaller($tplInstaller);
        
        // AI SQL schema
        $modelLoader = $this->getWorkbench()->model()->getModelLoader();
        $modelDataSource = $modelLoader->getDataConnection();
        $installerClass = get_class($modelLoader->getInstaller()->getInstallers()[0]);
        $schema_installer = new $installerClass($this->getSelector());
        if ($schema_installer instanceof AbstractSqlDatabaseInstaller) {
            $schema_installer
            ->setFoldersWithMigrations(['Migrations'])
            ->setDataConnection($modelDataSource)
            ->setFoldersWithStaticSql(['Views'])
            ->setMigrationsTableName('_migrations_genai');
            
            $installer->addInstaller($schema_installer); 
        } else {
            $this->getWorkbench()->getLogger()->error('Cannot initialize DB installer for app "' . $this->getSelector()->toString() . '": the cores model loader installer must be compatible with AbstractSqlDatabaseInstaller!');
        }
        
        return $installer;
    }
}