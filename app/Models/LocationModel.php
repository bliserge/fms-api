<?php

namespace App\Models;

use CodeIgniter\Model;

class LocationModel extends Model {
    protected $table = 'location';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'code', 'description', 'location_type', 'name', 'parent_id'];
    protected $useTimestamps = true;
	protected $createdField  = 'created_at';
	protected $updatedField  = 'updated_at';
}

