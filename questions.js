const surveyConfig = [
    {
        "id": "step-1",
        "title": "Informasi Pribadi",
        "icon": "fa-user-astronaut",
        "description": "",
        "questions": [
            {
                "type": "text",
                "name": "nama",
                "label": "Nama Lengkap",
                "required": true
            },
            {
                "type": "radio",
                "name": "usia",
                "label": "Usia",
                "required": true,
                "options": [
                    {
                        "value": "< 18 tahun",
                        "label": "< 18 tahun"
                    },
                    {
                        "value": "18 - 30 tahun",
                        "label": "18 - 30 tahun"
                    },
                    {
                        "value": "30 - 50 tahun",
                        "label": "30 - 50 tahun"
                    },
                    {
                        "value": "> 50 tahun",
                        "label": "> 50 tahun"
                    }
                ]
            },
            {
                "type": "radio",
                "name": "domisili",
                "label": "Domisili Tempat Tinggal",
                "required": true,
                "options": [
                    {
                        "value": "Kota Samarinda",
                        "label": "Kota Samarinda"
                    },
                    {
                        "value": "Kota Bontang",
                        "label": "Kota Bontang"
                    },
                    {
                        "value": "Kab. Kutai Kartanegara",
                        "label": "Kab. Kutai Kartanegara"
                    },
                    {
                        "value": "Kab. Kutai Timur",
                        "label": "Kab. Kutai Timur"
                    },
                    {
                        "value": "Kab. Kutai Barat",
                        "label": "Kab. Kutai Barat"
                    }
                ],
                "hasOther": true
            },
            {
                "type": "radio",
                "name": "pekerjaan",
                "label": "Pekerjaan",
                "required": true,
                "options": [
                    {
                        "value": "PNS/ Peg. Pemerintah",
                        "label": "PNS/ Peg. Pemerintah"
                    },
                    {
                        "value": "TNI/ Polri",
                        "label": "TNI/ Polri"
                    },
                    {
                        "value": "Pegawai BUMN",
                        "label": "Pegawai BUMN"
                    },
                    {
                        "value": "Pegawai Swasta",
                        "label": "Pegawai Swasta"
                    },
                    {
                        "value": "Pelajar (Mahasiswa/Siswa)",
                        "label": "Pelajar (Mahasiswa/Siswa)"
                    }
                ],
                "hasOther": true
            },
            {
                "type": "radio",
                "name": "pendapatan",
                "label": "Pendapatan per Bulan",
                "required": true,
                "options": [
                    {
                        "value": "< Rp 5 Juta",
                        "label": "< Rp 5 Juta"
                    },
                    {
                        "value": "Rp 5 - 10 Juta",
                        "label": "Rp 5 - 10 Juta"
                    },
                    {
                        "value": "> Rp 10 Juta",
                        "label": "> Rp 10 Juta"
                    }
                ]
            },
            {
                "type": "checkbox",
                "name": "pendapatan_th",
                "label": "Pendapatan Per Tahun",
                "required": true,
                "options": [
                    {
                        "value": "1 M",
                        "label": "1 M"
                    },
                    {
                        "value": "2 M",
                        "label": "2 M"
                    },
                    {
                        "value": "3 M",
                        "label": "3 M"
                    }
                ]
            }
        ]
    },
    {
        "id": "step-2",
        "title": "Rute Domestik Baru",
        "icon": "fa-plane-up",
        "description": "Penambahan rute penerbangan domestik apa saja yang anda inginkan untuk terbang dari APT Pranoto?",
        "questions": [
            {
                "type": "checkbox",
                "name": "domestik_destinasi",
                "label": "Pilih Destinasi Domestik",
                "required": true,
                "options": [
                    {
                        "value": "Denpasar",
                        "label": "Denpasar"
                    },
                    {
                        "value": "Makassar",
                        "label": "Makassar"
                    },
                    {
                        "value": "Tarakan",
                        "label": "Tarakan"
                    },
                    {
                        "value": "Semarang",
                        "label": "Semarang"
                    },
                    {
                        "value": "Manado",
                        "label": "Manado"
                    },
                    {
                        "value": "Medan",
                        "label": "Medan"
                    }
                ]
            },
            {
                "type": "radio",
                "name": "domestik_tujuan",
                "label": "Tujuan bepergian ke rute tersebut?",
                "required": true,
                "options": [
                    {
                        "value": "Bekerja",
                        "label": "Bekerja"
                    },
                    {
                        "value": "Bisnis",
                        "label": "Bisnis"
                    },
                    {
                        "value": "Pendidikan",
                        "label": "Pendidikan"
                    },
                    {
                        "value": "Wisata",
                        "label": "Wisata"
                    },
                    {
                        "value": "Keluarga",
                        "label": "Keluarga"
                    }
                ]
            },
            {
                "type": "select",
                "name": "domestik_frekuensi",
                "label": "Seberapa Sering?",
                "required": true,
                "options": [
                    {
                        "value": "Setahun sekali",
                        "label": "Setahun sekali"
                    },
                    {
                        "value": "6 bulan sekali",
                        "label": "6 bulan sekali"
                    },
                    {
                        "value": "3 bulan sekali",
                        "label": "3 bulan sekali"
                    },
                    {
                        "value": "Setiap bulan",
                        "label": "Setiap bulan"
                    },
                    {
                        "value": "> 12 kali setahun",
                        "label": "> 12 kali setahun"
                    }
                ]
            },
            {
                "type": "select",
                "name": "domestik_harga",
                "label": "Ekspektasi Harga Tiket",
                "required": true,
                "options": [
                    {
                        "value": "Rp 800k - 1,5jt",
                        "label": "Rp 800k - 1,5jt"
                    },
                    {
                        "value": "Rp 1,5jt - 2jt",
                        "label": "Rp 1,5jt - 2jt"
                    },
                    {
                        "value": "> Rp 2jt",
                        "label": "> Rp 2jt"
                    }
                ]
            },
            {
                "type": "select",
                "name": "domestik_pesawat",
                "label": "Jenis Pesawat",
                "required": true,
                "options": [
                    {
                        "value": "LCC (Low Cost)",
                        "label": "LCC (Low Cost)"
                    },
                    {
                        "value": "Medium Service",
                        "label": "Medium Service"
                    },
                    {
                        "value": "Full Service",
                        "label": "Full Service"
                    }
                ]
            },
            {
                "type": "select",
                "name": "domestik_jam",
                "label": "Jam Penerbangan Ideal",
                "required": true,
                "options": [
                    {
                        "value": "Pagi (07:00 - 09:00)",
                        "label": "Pagi (07:00 - 09:00)"
                    },
                    {
                        "value": "Siang (10:00 - 14:00)",
                        "label": "Siang (10:00 - 14:00)"
                    },
                    {
                        "value": "Sore (15:00 - 17:00)",
                        "label": "Sore (15:00 - 17:00)"
                    },
                    {
                        "value": "Malam (18:00 - 20:00)",
                        "label": "Malam (18:00 - 20:00)"
                    }
                ]
            },
            {
                "type": "day-selector",
                "name": "domestik_hari",
                "label": "Hari Penerbangan Ideal",
                "required": true,
                "options": [
                    {
                        "value": "Sen",
                        "label": "Sen"
                    },
                    {
                        "value": "Sel",
                        "label": "Sel"
                    },
                    {
                        "value": "Rab",
                        "label": "Rab"
                    },
                    {
                        "value": "Kam",
                        "label": "Kam"
                    },
                    {
                        "value": "Jum",
                        "label": "Jum"
                    },
                    {
                        "value": "Sab",
                        "label": "Sab"
                    },
                    {
                        "value": "Min",
                        "label": "Min"
                    }
                ]
            },
            {
                "type": "textarea",
                "name": "domestik_saran",
                "label": "Saran & Masukan Rute Domestik",
                "required": false
            }
        ]
    },
    {
        "id": "step-3",
        "title": "Rute Internasional Baru",
        "icon": "fa-globe",
        "description": "Penambahan rute penerbangan internasional apa saja yang diinginkan dari APT Pranoto?",
        "questions": [
            {
                "type": "checkbox",
                "name": "int_destinasi",
                "label": "Pilih Destinasi Internasional",
                "required": true,
                "options": [
                    {
                        "value": "Kuala Lumpur",
                        "label": "Kuala Lumpur"
                    },
                    {
                        "value": "Singapura",
                        "label": "Singapura"
                    },
                    {
                        "value": "Bangkok",
                        "label": "Bangkok"
                    }
                ],
                "hasOther": true
            },
            {
                "type": "select",
                "name": "int_tujuan",
                "label": "Tujuan bepergian?",
                "required": true,
                "options": [
                    {
                        "value": "Bekerja",
                        "label": "Bekerja"
                    },
                    {
                        "value": "Bisnis",
                        "label": "Bisnis"
                    },
                    {
                        "value": "Pendidikan",
                        "label": "Pendidikan"
                    },
                    {
                        "value": "Wisata",
                        "label": "Wisata"
                    },
                    {
                        "value": "Keluarga",
                        "label": "Keluarga"
                    }
                ]
            },
            {
                "type": "select",
                "name": "int_frekuensi",
                "label": "Seberapa Sering?",
                "required": true,
                "options": [
                    {
                        "value": "Setahun sekali",
                        "label": "Setahun sekali"
                    },
                    {
                        "value": "6 bulan sekali",
                        "label": "6 bulan sekali"
                    },
                    {
                        "value": "3 bulan sekali",
                        "label": "3 bulan sekali"
                    },
                    {
                        "value": "Setiap bulan",
                        "label": "Setiap bulan"
                    },
                    {
                        "value": "> 12 kali setahun",
                        "label": "> 12 kali setahun"
                    }
                ]
            },
            {
                "type": "select",
                "name": "int_harga",
                "label": "Ekspektasi Harga Tiket",
                "required": true,
                "options": [
                    {
                        "value": "Rp 800k - 1,5jt",
                        "label": "Rp 800k - 1,5jt"
                    },
                    {
                        "value": "Rp 1,5jt - 2jt",
                        "label": "Rp 1,5jt - 2jt"
                    },
                    {
                        "value": "> Rp 2jt",
                        "label": "> Rp 2jt"
                    }
                ]
            },
            {
                "type": "select",
                "name": "int_pesawat",
                "label": "Jenis Pesawat",
                "required": true,
                "options": [
                    {
                        "value": "LCC",
                        "label": "LCC"
                    },
                    {
                        "value": "Medium",
                        "label": "Medium"
                    },
                    {
                        "value": "Full Service",
                        "label": "Full Service"
                    }
                ]
            },
            {
                "type": "select",
                "name": "int_jam",
                "label": "Jam Penerbangan",
                "required": true,
                "options": [
                    {
                        "value": "Pagi",
                        "label": "Pagi"
                    },
                    {
                        "value": "Siang",
                        "label": "Siang"
                    },
                    {
                        "value": "Sore",
                        "label": "Sore"
                    },
                    {
                        "value": "Malam",
                        "label": "Malam"
                    }
                ]
            },
            {
                "type": "day-selector",
                "name": "int_hari",
                "label": "Hari Penerbangan Ideal",
                "required": true,
                "options": [
                    {
                        "value": "Sen",
                        "label": "Sen"
                    },
                    {
                        "value": "Sel",
                        "label": "Sel"
                    },
                    {
                        "value": "Rab",
                        "label": "Rab"
                    },
                    {
                        "value": "Kam",
                        "label": "Kam"
                    },
                    {
                        "value": "Jum",
                        "label": "Jum"
                    },
                    {
                        "value": "Sab",
                        "label": "Sab"
                    },
                    {
                        "value": "Min",
                        "label": "Min"
                    }
                ]
            },
            {
                "type": "textarea",
                "name": "int_saran",
                "label": "Saran & Masukan Rute Internasional",
                "required": false
            }
        ]
    },
    {
        "id": "step-4",
        "title": "Tambah Frekuensi Penerbangan",
        "icon": "fa-clock-rotate-left",
        "description": "Penambahan frekuensi jadwal rute penerbangan domestik yang sudah ada saat ini.",
        "questions": [
            {
                "type": "checkbox",
                "name": "freq_rute",
                "label": "Pilih Rute yang Ditambah Frekuensinya",
                "required": true,
                "options": [
                    {
                        "value": "Jakarta",
                        "label": "Jakarta"
                    },
                    {
                        "value": "Surabaya",
                        "label": "Surabaya"
                    },
                    {
                        "value": "Yogyakarta",
                        "label": "Yogyakarta"
                    },
                    {
                        "value": "Banjarmasin",
                        "label": "Banjarmasin"
                    },
                    {
                        "value": "Berau",
                        "label": "Berau"
                    }
                ]
            },
            {
                "type": "select",
                "name": "freq_tujuan",
                "label": "Tujuan bepergian?",
                "required": true,
                "options": [
                    {
                        "value": "Bekerja",
                        "label": "Bekerja"
                    },
                    {
                        "value": "Bisnis",
                        "label": "Bisnis"
                    },
                    {
                        "value": "Pendidikan",
                        "label": "Pendidikan"
                    },
                    {
                        "value": "Wisata",
                        "label": "Wisata"
                    },
                    {
                        "value": "Keluarga",
                        "label": "Keluarga"
                    }
                ]
            },
            {
                "type": "select",
                "name": "freq_frekuensi",
                "label": "Seberapa Sering?",
                "required": true,
                "options": [
                    {
                        "value": "Setahun sekali",
                        "label": "Setahun sekali"
                    },
                    {
                        "value": "6 bulan sekali",
                        "label": "6 bulan sekali"
                    },
                    {
                        "value": "3 bulan sekali",
                        "label": "3 bulan sekali"
                    },
                    {
                        "value": "Setiap bulan",
                        "label": "Setiap bulan"
                    }
                ]
            },
            {
                "type": "select",
                "name": "freq_harga",
                "label": "Ekspektasi Harga Tiket",
                "required": true,
                "options": [
                    {
                        "value": "Rp 800k - 1,5jt",
                        "label": "Rp 800k - 1,5jt"
                    },
                    {
                        "value": "Rp 1,5jt - 2jt",
                        "label": "Rp 1,5jt - 2jt"
                    },
                    {
                        "value": "> Rp 2jt",
                        "label": "> Rp 2jt"
                    }
                ]
            },
            {
                "type": "day-selector",
                "name": "freq_hari",
                "label": "Hari Tambahan yang Diinginkan",
                "required": true,
                "options": [
                    {
                        "value": "Sen",
                        "label": "Sen"
                    },
                    {
                        "value": "Sel",
                        "label": "Sel"
                    },
                    {
                        "value": "Rab",
                        "label": "Rab"
                    },
                    {
                        "value": "Kam",
                        "label": "Kam"
                    },
                    {
                        "value": "Jum",
                        "label": "Jum"
                    },
                    {
                        "value": "Sab",
                        "label": "Sab"
                    },
                    {
                        "value": "Min",
                        "label": "Min"
                    }
                ]
            },
            {
                "type": "textarea",
                "name": "freq_saran",
                "label": "Saran & Masukan",
                "required": true
            }
        ]
    }
];