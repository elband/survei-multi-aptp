const regionData = {
    "Provinsi": [
        "Aceh", "Sumatera Utara", "Sumatera Barat", "Riau", "Kepulauan Riau", "Jambi", 
        "Sumatera Selatan", "Bangka Belitung", "Bengkulu", "Lampung", "DKI Jakarta", 
        "Jawa Barat", "Banten", "Jawa Tengah", "DI Yogyakarta", "Jawa Timur", 
        "Bali", "Nusa Tenggara Barat", "Nusa Tenggara Timur", "Kalimantan Barat", 
        "Kalimantan Tengah", "Kalimantan Selatan", "Kalimantan Timur", "Kalimantan Utara", 
        "Sulawesi Utara", "Sulawesi Tengah", "Sulawesi Selatan", "Sulawesi Tenggara", 
        "Gorontalo", "Sulawesi Barat", "Maluku", "Maluku Utara", "Papua", 
        "Papua Barat", "Papua Selatan", "Papua Tengah", "Papua Pegunungan"
    ],
    "Kota": {
        "Kalimantan Timur": ["Samarinda", "Balikpapan", "Bontang", "Kutai Kartanegara", "Kutai Timur", "Kutai Barat", "Berau", "Paser", "Penajam Paser Utara", "Mahakam Ulu"],
        "Kalimantan Selatan": ["Banjarmasin", "Banjarbaru", "Banjar", "Tanah Laut", "Kotabaru", "Barito Kuala", "Tapin", "Hulu Sungai Selatan", "Hulu Sungai Tengah", "Hulu Sungai Utara", "Tabalong", "Tanah Bumbu", "Balangan"],
        "Kalimantan Tengah": ["Palangkaraya", "Kotawaringin Barat", "Kotawaringin Timur", "Kapuas", "Barito Selatan", "Barito Utara", "Sukamara", "Lamandau", "Seruyan", "Katingan", "Pulang Pisau", "Gunung Mas", "Barito Timur", "Murung Raya"],
        "Kalimantan Barat": ["Pontianak", "Singkawang", "Sambas", "Bengkayang", "Landak", "Mempawah", "Sanggau", "Ketapang", "Sintang", "Kapuas Hulu", "Sekadau", "Melawi", "Kayong Utara", "Kubu Raya"],
        "Kalimantan Utara": ["Tarakan", "Bulungan", "Malinau", "Nunukan", "Tana Tidung"],
        "DKI Jakarta": ["Jakarta Pusat", "Jakarta Utara", "Jakarta Barat", "Jakarta Selatan", "Jakarta Timur", "Kepulauan Seribu"],
        "Jawa Barat": ["Bandung", "Bekasi", "Bogor", "Depok", "Cimahi", "Tasikmalaya", "Banjar", "Cirebon", "Sukabumi", "Garut", "Cianjur", "Ciamis", "Kuningan", "Majalengka", "Sumedang", "Indramayu", "Subang", "Purwakarta", "Karawang", "Bandung Barat", "Pangandaran"],
        "Jawa Tengah": ["Semarang", "Surakarta", "Magelang", "Pekalongan", "Salatiga", "Tegal", "Cilacap", "Banyumas", "Purbalingga", "Banjarnegara", "Kebumen", "Purworejo", "Wonosobo", "Boyolali", "Klaten", "Sukoharjo", "Wonogiri", "Karanganyar", "Sragen", "Grobogan", "Blora", "Rembang", "Pati", "Kudus", "Jepara", "Demak", "Temanggung", "Kendal", "Batang", "Pemalang", "Brebes"],
        "Jawa Timur": ["Surabaya", "Malang", "Batu", "Blitar", "Kediri", "Madiun", "Mojokerto", "Pasuruan", "Probolinggo", "Sidoarjo", "Gresik", "Lamongan", "Tuban", "Bojonegoro", "Ngawi", "Magetan", "Ponorogo", "Pacitan", "Trenggalek", "Tulungagung", "Nganjuk", "Jombang", "Mojokerto", "Sampang", "Pamekasan", "Sumenep", "Bangkalan"],
        "DI Yogyakarta": ["Yogyakarta", "Sleman", "Bantul", "Kulon Progo", "Gunungkidul"],
        "Bali": ["Denpasar", "Badung", "Bangli", "Buleleng", "Gianyar", "Jembrana", "Karangasem", "Klungkung", "Tabanan"]
    }
};

function setupRegionSelectors(container) {
    const selects = Array.from(container.querySelectorAll('select'));
    let provinsiSelect = null;
    let kotaSelect = null;

    selects.forEach(select => {
        const name = (select.name || '').toLowerCase();
        const labelText = select.previousElementSibling ? select.previousElementSibling.innerText.toLowerCase() : '';

        if (name.includes('provinsi') || labelText.includes('provinsi')) {
            provinsiSelect = select;
        } else if (name.includes('kota') || name.includes('kabupaten') || labelText.includes('kota') || labelText.includes('kabupaten')) {
            kotaSelect = select;
        }
    });

    if (!provinsiSelect || !kotaSelect) return;

    // Bersihkan opsi bawaan
    provinsiSelect.innerHTML = '<option value="" disabled selected>Pilih Provinsi</option>';

    // Populate Provinsi
    regionData["Provinsi"].forEach(prov => {
        const opt = document.createElement('option');
        opt.value = prov;
        opt.innerText = prov;
        provinsiSelect.appendChild(opt);
    });

    provinsiSelect.addEventListener('change', function() {
        const selectedProv = this.value;
        
        // Reset Kota
        kotaSelect.innerHTML = '<option value="" disabled selected>Pilih Kota/Kabupaten...</option>';
        
        if (regionData["Kota"][selectedProv]) {
            regionData["Kota"][selectedProv].forEach(kota => {
                const opt = document.createElement('option');
                opt.value = kota;
                opt.innerText = kota;
                kotaSelect.appendChild(opt);
            });
        } else {
            // Jika data kota tidak ada di list hardcode, tampilkan opsi umum atau biarkan input manual?
            // Untuk sekarang kita biarkan "Lainnya"
            const opt = document.createElement('option');
            opt.value = "Lainnya";
            opt.innerText = "Lainnya (Luar Wilayah Prioritas)";
            kotaSelect.appendChild(opt);
        }
    });
}
