<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rôle product_manager et permissions du back-office.
 *
 * - users.role (enum) accepte désormais `product_manager`.
 * - users.admin_permissions : sections accordées par l'admin en plus de
 *   celles du rôle (voir config/admin_access.php).
 * - users.created_by_admin_id : admin qui a créé le gestionnaire.
 */
return new class extends Migration
{
    private const ROLES = ['admin', 'client', 'vendeur', 'livreur', 'product_manager'];

    private const PREVIOUS_ROLES = ['admin', 'client', 'vendeur', 'livreur'];

    public function up(): void
    {
        $this->setAllowedRoles(self::ROLES);

        Schema::table('users', function (Blueprint $table) {
            $table->json('admin_permissions')->nullable()->after('roles');
            $table->foreignId('created_by_admin_id')->nullable()->after('admin_permissions')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_admin_id');
            $table->dropColumn('admin_permissions');
        });

        // Les gestionnaires redeviennent de simples clients avant de resserrer la contrainte.
        DB::table('users')->where('role', 'product_manager')->update(['role' => 'client']);
        $this->setAllowedRoles(self::PREVIOUS_ROLES);
    }

    private function setAllowedRoles(array $roles): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($r) => "'{$r}'::character varying", $roles));
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (((role)::text = ANY ((ARRAY[{$list}])::text[])))");

            return;
        }

        Schema::table('users', function (Blueprint $table) use ($roles) {
            $table->enum('role', $roles)->default('client')->change();
        });
    }
};
