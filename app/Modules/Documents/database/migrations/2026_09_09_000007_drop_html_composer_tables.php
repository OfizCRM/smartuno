<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Removes the short-lived HTML composer.
     *
     * It was replaced by ONLYOFFICE, which edits the Office file itself rather
     * than authoring a parallel HTML source and rendering it. Neither table ever
     * reached a customer's installation, so there is nothing to migrate out.
     */
    public function up(): void
    {
        Schema::dropIfExists('document_bodies');
        Schema::dropIfExists('document_templates');
    }

    public function down(): void
    {
        // Deliberately empty: the composer these belonged to is gone, and
        // recreating the tables would leave two empty shells nothing writes to.
    }
};
