from docx import Document
from docx.shared import Pt, Inches
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.style import WD_STYLE_TYPE

def add_checkbox(paragraph, checked=False):
    # Simulasi checkbox di Word dengan karakter Unicode
    symbol = "☑" if checked else "☐"
    paragraph.add_run(f"{symbol} ")

def add_heading(doc, text, level=1):
    doc.add_paragraph()
    p = doc.add_paragraph()
    run = p.add_run(text)
    run.bold = True
    if level == 1:
        p.style = doc.styles['Heading 1']
    elif level == 2:
        p.style = doc.styles['Heading 2']
    elif level == 3:
        p.style = doc.styles['Heading 3']

def add_section(doc, title):
    add_heading(doc, title, 1)

def add_checkbox_line(doc, text, checked=False):
    p = doc.add_paragraph(style='List Bullet')
    run = p.add_run("☐ " if not checked else "☑ ")
    run.bold = True
    p.add_run(text)

def add_text(doc, text):
    p = doc.add_paragraph(text)
    return p

def create_doc():
    doc = Document()

    # Set margin (opsional)
    sections = doc.sections
    for section in sections:
        section.top_margin = Inches(0.7)
        section.bottom_margin = Inches(0.7)
        section.left_margin = Inches(0.7)
        section.right_margin = Inches(0.7)

    # Title
    title = doc.add_paragraph()
    title_run = title.add_run("SYSTEM TEST PLAN")
    title_run.bold = True
    title_run.font.size = Pt(18)
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER

    subtitle = doc.add_paragraph()
    subtitle_run = subtitle.add_run("RAB-PROJECT: Proyek hingga Laporan Keuangan")
    subtitle_run.bold = True
    subtitle_run.font.size = Pt(12)
    subtitle.alignment = WD_ALIGN_PARAGRAPH.CENTER

    doc.add_paragraph("Tujuan dokumen: memandu pengujian sistem secara berurutan dan mencatat hasilnya.")
    doc.add_paragraph("Lingkungan: Dev / Test")
    doc.add_paragraph("Tanggal: ____________________     Tester: ____________________")
    doc.add_paragraph("Versi/commit aplikasi: ____________________     Database: ____________________")

    doc.add_paragraph()
    add_heading(doc, "1. Data dan Prasyarat Pengujian", 1)

    add_checkbox_line(doc, "Akun tester tersedia dengan hak akses sesuai modul yang diuji.")
    add_checkbox_line(doc, "Data klien dan supplier uji tersedia.")
    add_checkbox_line(doc, "Master material, satuan, gudang, lokasi/bin tersedia.")
    add_checkbox_line(doc, "COA dan fiscal_config untuk jurnal yang diuji tersedia dan aktif.")
    add_checkbox_line(doc, "Periode akuntansi untuk tanggal transaksi tersedia dan terbuka.")
    add_checkbox_line(doc, "Database dev/test sudah di-backup.")
    add_checkbox_line(doc, "Dipastikan pengujian tidak menggunakan transaksi atau data production.")

    doc.add_paragraph()
    add_heading(doc, "2. Urutan Pengujian End-to-End", 1)

    # Tahap A
    add_heading(doc, "Tahap A — Proyek, AI, task, dan RAB", 2)

    add_checkbox_line(doc, "ST-01 — Buat proyek dengan data wajib dan simpan.")
    add_checkbox_line(doc, "ST-02 — Jalankan Analisis & Bagi Task (AI) pada proyek uji.")
    add_checkbox_line(doc, "ST-03 — Tinjau dan simpan Task/BoQ ke proyek.")
    add_checkbox_line(doc, "ST-04 — Preview dan penerbitan invoice otomatis.")

    # Tahap B
    add_heading(doc, "Tahap B — Pengadaan dan persediaan masuk", 2)

    add_checkbox_line(doc, "ST-05 — Purchase Planning.")
    add_checkbox_line(doc, "ST-06 — Buat dan proses PO.")
    add_checkbox_line(doc, "ST-07 — Buat GRN.")
    add_checkbox_line(doc, "ST-08 — WH Receiving, pergerakan stok, dan jurnal penerimaan.")

    # Tahap C
    add_heading(doc, "Tahap C — Invoice, pembayaran, dan progres proyek", 2)

    add_checkbox_line(doc, "ST-09 — Invoice termin dan jurnal penagihan.")
    add_checkbox_line(doc, "ST-10 — Pembayaran klien dan deposit.")
    add_checkbox_line(doc, "ST-11 — Ajukan dan verifikasi progres.")

    # Tahap D
    add_heading(doc, "Tahap D — SPK, issue material, dan analisis AI", 2)

    add_checkbox_line(doc, "ST-12 — Buat dan terbitkan SPK.")
    add_checkbox_line(doc, "ST-13 — Warehouse Issuing dengan SPK.")
    add_checkbox_line(doc, "ST-14 — AI Variance Analysis.")

    # Tahap E
    add_heading(doc, "Tahap E — Rekonsiliasi jurnal dan laporan keuangan", 2)

    add_checkbox_line(doc, "ST-15 — Periksa auto-posting dan konsistensi transaksi.")
    add_checkbox_line(doc, "ST-16 — Verifikasi laporan keuangan dan dashboard.")

    doc.add_paragraph()
    add_heading(doc, "3. Kriteria Kelulusan", 1)

    add_checkbox_line(doc, "Semua test wajib selesai, atau test yang terblokir memiliki tiket tindak lanjut.")
    add_checkbox_line(doc, "Validasi penting dijalankan di backend, bukan hanya di UI.")
    add_checkbox_line(doc, "Tidak ada issue tanpa SPK valid jika sistem mensyaratkannya.")
    add_checkbox_line(doc, "Tidak ada stok yang berubah tanpa dokumen transaksi resmi.")
    add_checkbox_line(doc, "Tidak ada invoice atau jurnal yang duplikat atau tidak tertaut ke sumber.")
    add_checkbox_line(doc, "Jurnal seimbang dan laporan dapat direkonsiliasi.")
    add_checkbox_line(doc, "Setiap reversal tercatat dan tercermin dengan benar pada stok serta laporan.")

    doc.add_paragraph()
    add_heading(doc, "4. Ringkasan Hasil", 1)

    doc.add_paragraph("Jumlah test lulus: ______")
    doc.add_paragraph("Gagal: ______")
    doc.add_paragraph("Terblokir: ______")
    doc.add_paragraph("Belum diuji: ______")

    doc.add_paragraph()
    doc.add_paragraph("Blocker utama / tiket tindak lanjut:")
    doc.add_paragraph("____________________________________________________________________________")
    doc.add_paragraph("____________________________________________________________________________")

    doc.add_paragraph()
    doc.add_paragraph("Tester: ____________________   Tanggal: __________   Tanda tangan: ____________________")
    doc.add_paragraph("Reviewer bisnis/keuangan: ____________________   Tanggal: __________   Tanda tangan: ____________________")

    doc.add_paragraph()
    doc.add_paragraph("Keputusan: ☐ Siap dilanjutkan   ☐ Perlu perbaikan dan retest   ☐ Belum boleh dirilis")

    # Save file
    output_file = "SYSTEM_TEST_PLAN_RAB_PROJECT.docx"
    doc.save(output_file)
    print(f"File berhasil dibuat: {output_file}")

if __name__ == "__main__":
    create_doc()
