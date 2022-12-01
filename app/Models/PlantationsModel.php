<?php

namespace App\Models;

use CodeIgniter\Model;

class PlantationsModel extends Model {
    protected $table = 'plantations';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'ownerId', 'plot_id', 'num_trees', 'treeType', 'plantation-date', 'harvest-date', 'status'];
    protected $useTimestamps = true;
	protected $createdField  = 'created_at';
	protected $updatedField  = 'updated_at';
}

