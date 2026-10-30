<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\BooksImport;
use App\Models\Shop;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class BookImportController extends Controller
{
    public function form()
    {
        return view('admin.books.import', [
            'shops' => Shop::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'file' => 'required|file|mimes:csv,txt,xlsx|max:5120',
        ]);

        $shop = Shop::findOrFail($request->shop_id);
        $file = $request->file('file');

        // CSV exporté depuis Excel FR : séparateur « ; » ; sinon « , ».
        $delimiter = ',';
        if (in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            $firstLine = (string) fgets(fopen($file->getRealPath(), 'r'));
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        }

        // Vérifie que user_id existe bien sur ta table shops ; sinon utilise $request->user()->id.
        $import = new BooksImport($shop->id, $shop->user_id ?? $request->user()->id, $delimiter);
        Excel::import($import, $file);

        return back()->with('import_report', [
            'created' => $import->created,
            'updated' => $import->updated,
            'errors' => $import->errors,
        ]);
    }

    public function template()
    {
        $header = 'titre,auteur,prix,categorie,isbn,editeur,langue,format,pages,annee,stock,poids,description';
        $example = 'Une si longue lettre,Mariama Bâ,5000,Livres,9782708703476,Les Nouvelles Éditions Africaines,fr,paperback,131,1979,20,0.3,Roman épistolaire';

        return response()->streamDownload(function () use ($header, $example) {
            echo "\xEF\xBB\xBF" . $header . "\n" . $example . "\n";
        }, 'modele-import-livres.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
