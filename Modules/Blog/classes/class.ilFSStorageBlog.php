<?php

/* Copyright (c) 1998-2019 ILIAS open source, Extended GPL, see docs/LICENSE */
/**
 *
 * @author Jörg Lützenkirchen <luetzenkirchen@leifos.com>
 */
class ilFSStorageBlog extends ilFileSystemStorage
{
    public function __construct($a_container_id = 0)
    {
        parent::__construct(self::STORAGE_SECURED, true, $a_container_id);
    }
    
    protected function getPathPostfix()
    {
        return 'blog';
    }
    
    protected function getPathPrefix()
    {
        return 'ilBlog';
    }
}
