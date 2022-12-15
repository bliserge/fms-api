<?php

namespace App\Models;

use CodeIgniter\Model;

class TreeCategiriesModel extends Model {
    protected $table = 'tree-categories';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'title', 'days_to_harvest'];
    protected $useTimestamps = false;
}

