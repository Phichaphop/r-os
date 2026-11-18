Test PDFs for S01 (all generated, no real data):
- a.pdf (3 pages, A4, link annotation, Info metadata) and b.pdf (2 pages, landscape) — ReportLab
- a_objstm.pdf — qpdf --object-streams=generate (cross-reference stream + object streams)
- a_aes256 / a_aes128 / a_rc4 / a_rc4_40.pdf — qpdf --encrypt, user password "user1", owner "owner1"
- a_broken.pdf (cross-reference table cut off), a_objstm_broken.pdf (startxref points nowhere)
- js.pdf — a.pdf with document JavaScript (pypdf) — must be refused
- img.jpg, alpha.png (transparent), pal.png (palette) — Pillow
