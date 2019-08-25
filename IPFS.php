<?php

/*
* This file is part of the Pho package.
*
* (c) Emre Sokullu <emre@phonetworks.org>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Pho\Kernel\Services\Storage\Adapters;

use Pho\Kernel\Kernel;
use Pho\Kernel\Services\ServiceInterface;
use Pho\Kernel\Services\Storage\StorageInterface;
use Pho\Kernel\Services\Storage\Exceptions\InaccessiblePathException;
use IPFSPHP\IPFS as IPFSclient;
use Predis\Client;

/**
* IPFS Adapter for Storage
*
* IPFS is a decentralized storage alternative for Pho Networks stack.
* Unlike other storage adapters, IPFS relies on a database (Redis in
* this particular instance) because the pathnames in IPFS are 
* unpredicatable and not human-readable.
*
* Comes with a backup adapter just in case IPFS data is lost (which do 
* happen.) The backup adapter may be left null.
*
* @author Emre Sokullu
*/
class IPFS implements StorageInterface, ServiceInterface
{

    /**
     * The Redis client
     *
     * @var Predis\Client
     */
    private $redis;

    /**
     * The IPFS client
     *
     * @var IPFSPHP\IPFS
     */
    private $ipfs;

    /**
     * Stateful kernel to access services such as Logger.
     *
     * @var Kernel
     */
    private $kernel;

    private $backup = null;
    
    /**
     * Constructor.
     * 
     * @param Kernel $kernel The Pho Kernel to access services
     * @param string $options In Json format, as follows: {"hostname", "port", "api_port", "redis", "backup"}
     */
    public function __construct(Kernel $kernel, string $options)
    {
        $this->kernel = $kernel;
        $options = json_decode($options, true);
        $this->ipfs = new IPFSclient($options["hostname"], $options["port"], $options["api_port"]);
        $this->redis = new Client($options["redis"]);
        $this->kernel->logger()->info(
            sprintf("The storage service has started with the %s adapter.", __CLASS__)
        );
        if(isset($options["backup"])) {
            // set up backup
        }
    }

    private function backup(string $op, array $args): void
    {
        if(is_null($this->backup)) {
            return;
        }
        $this->backup->$op(...$args);
    }
    
    /**
    * {@inheritdoc}
    */
    public function get(string $path): string
    {
        return $this->redis->get($this->path_normalize($path));
    }
    
    /**
    * {@inheritdoc}
    */
    public function mkdir(string $dir, bool $recursive = true): void
    {
        if(!$recursive) {
            // return Exception
        }
        $dir = $this->path_normalize($dir);
        $dir = trim($dir, "/");
        $exploded = explode("/", $dir);
        $prevs = ["/"];
        foreach($exploded as $i=>$ex) {
            $ex = $prevs[$i].$ex."/";
            $prevs[] = $ex;
            foreach($prevs as $prev) {
                $this->redis->sadd($prev, $ex);
            }
        }
        $this->backup(__METHOD__, func_get_args());
    }
    
    /**
    * {@inheritdoc}
    */
    public function file_exists(string $path): bool
    {
        return !empty($this->get($path));
    }
    
    /**
    * {@inheritdoc}
    * ipfs equivalent of /add
    */
    public function put(string $file, string $path): void
    {
        $result = $this->ipfs->add($file);
        $this->redis->set($this->path_normalize($path), $result);
        $this->redis->set("/ipfs/".$result, $this->path_normalize($path));
        $this->backup(__METHOD__, func_get_args());
    }
    
    /**
    * {@inheritdoc}
    */
    public function append(string $file, string $path): void
    {
        throw new \Exception("method not implemented");
        // ipfs cat
        // append
        // ipfs add
        // redis set
        // ipfs rm
        $this->backup(__METHOD__, func_get_args());
    }
    
    
    /**
    * A private method that helps translate directory definition conforming to the operating system settings.
    *
    * @param string $path
    * @return void
    */
    private function path_normalize(string $path): string
    {
        return str_replace("\\", '/', $path);
    }
}
