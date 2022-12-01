<?php

namespace App\Models;

use CodeIgniter\Model;

class TreeCategiriesModel extends Model {
    protected $table = 'tree-categiries';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'title', 'days-to-harvest'];
    protected $useTimestamps = false;
}

