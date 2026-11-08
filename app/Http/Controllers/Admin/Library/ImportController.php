<?php

namespace App\Http\Controllers\Admin\Library;

use App\Http\Controllers\Controller;
use App\Imports\BooksImport;
use App\Models\Category;
use App\Models\Shop;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/** Ajout ou mise à jour de livres en masse depuis un CSV ou un classeur Excel. */
class ImportController extends Controller
{
    public function create()
    {
        return view('admin.library.import', [
            'shops' => Shop::where('status', 'active')->orderBy('name')->get(),
            'shelves' => Category::where('is_library', true)->with('subcategories')->orderBy('name')->get(),
            'columns' => BooksImport::COLUMNS,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'file' => 'required|file|mimes:csv,txt,xlsx|max:5120',
        ]);

        if (! Category::where('is_library', true)->exists()) {
            return back()->with('error', 'Créez d\'abord un rayon dans la Bibliothèque.');
        }

        $shop = Shop::findOrFail($request->shop_id);
        $file = $request->file('file');

        // Un CSV enregistré par Excel en français utilise « ; » : on le détecte sur l'en-tête.
        $delimiter = ',';
        if (in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            $handle = fopen($file->getRealPath(), 'r');
            $firstLine = (string) fgets($handle);
            fclose($handle);
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        }

        $import = new BooksImport($shop, $delimiter);
        Excel::import($import, $file);

        return redirect()->route('admin.library.import.create')
            ->withInput(['shop_id' => $shop->id])
            ->with('import_report', [
                'shop' => $shop->name,
                'created' => $import->created,
                'updated' => $import->updated,
                'errors' => $import->errors,
            ]);
    }

    public function template()
    {
        $example = ['Une si longue lettre', 'Mariama Bâ', '5000', 'Romans', '', '9782708703476', 'Les Nouvelles Éditions Africaines', 'fr', 'paperback', '131', '1979', '20', '0.3', 'Roman épistolaire'];

        return response()->streamDownload(function () use ($example) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8 correctement
            fputcsv($out, BooksImport::COLUMNS);
            fputcsv($out, $example);
            fclose($out);
        }, 'modele-import-livres.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
