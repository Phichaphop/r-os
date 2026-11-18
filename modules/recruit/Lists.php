<?php

declare(strict_types=1);

namespace Blue\Modules\Recruit;

/**
 * Fixed lists of the application form (phase 5E, M08). Provinces: the 77 provinces of Thailand (76 + Bangkok), in Thai —
 * shown as written whatever the screen language, because addresses are written in Thai.
 */
final class Lists
{
    public const PROVINCES = [
        'กรุงเทพมหานคร', 'กระบี่', 'กาญจนบุรี', 'กาฬสินธุ์', 'กำแพงเพชร', 'ขอนแก่น', 'จันทบุรี', 'ฉะเชิงเทรา', 'ชลบุรี', 'ชัยนาท',
        'ชัยภูมิ', 'ชุมพร', 'เชียงราย', 'เชียงใหม่', 'ตรัง', 'ตราด', 'ตาก', 'นครนายก', 'นครปฐม', 'นครพนม',
        'นครราชสีมา', 'นครศรีธรรมราช', 'นครสวรรค์', 'นนทบุรี', 'นราธิวาส', 'น่าน', 'บึงกาฬ', 'บุรีรัมย์', 'ปทุมธานี', 'ประจวบคีรีขันธ์',
        'ปราจีนบุรี', 'ปัตตานี', 'พระนครศรีอยุธยา', 'พะเยา', 'พังงา', 'พัทลุง', 'พิจิตร', 'พิษณุโลก', 'เพชรบุรี', 'เพชรบูรณ์',
        'แพร่', 'ภูเก็ต', 'มหาสารคาม', 'มุกดาหาร', 'แม่ฮ่องสอน', 'ยโสธร', 'ยะลา', 'ร้อยเอ็ด', 'ระนอง', 'ระยอง',
        'ราชบุรี', 'ลพบุรี', 'ลำปาง', 'ลำพูน', 'เลย', 'ศรีสะเกษ', 'สกลนคร', 'สงขลา', 'สตูล', 'สมุทรปราการ',
        'สมุทรสงคราม', 'สมุทรสาคร', 'สระแก้ว', 'สระบุรี', 'สิงห์บุรี', 'สุโขทัย', 'สุพรรณบุรี', 'สุราษฎร์ธานี', 'สุรินทร์', 'หนองคาย',
        'หนองบัวลำภู', 'อ่างทอง', 'อำนาจเจริญ', 'อุดรธานี', 'อุตรดิตถ์', 'อุทัยธานี', 'อุบลราชธานี',
    ];

    /** Stages of an application (7B): applied → screening → interview → offer → hired, or rejected / withdrawn / on_hold. */
    public const STAGES = ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected', 'withdrawn', 'on_hold'];

    /** Stages a recruiter may move an application to from each stage (hired only through the hire step). */
    public const MOVES = [
        'applied' => ['screening', 'rejected', 'withdrawn', 'on_hold'],
        'screening' => ['interview', 'rejected', 'withdrawn', 'on_hold'],
        'interview' => ['offer', 'rejected', 'withdrawn', 'on_hold'],
        'offer' => ['rejected', 'withdrawn', 'on_hold'],
        'on_hold' => ['screening', 'interview', 'rejected', 'withdrawn'],
        'hired' => [], 'rejected' => [], 'withdrawn' => [],
    ];

    /** Document groups of an application (M08 §5 defaults) => personnel file type it becomes on hire. */
    public const DOC_GROUPS = ['id' => 'id_card', 'house' => 'house_registration', 'bank' => 'bank_book', 'education' => 'education_certificate', 'photo' => 'photo', 'other' => 'application'];

    public const EDU_LEVELS = ['primary', 'secondary', 'vocational', 'high_vocational', 'bachelor', 'master', 'doctorate'];
    public const LANG_LEVELS = ['none', 'basic', 'fair', 'good', 'fluent'];
    public const MARITAL = ['single', 'married', 'divorced', 'widowed', 'other'];
    public const MILITARY = ['done', 'exempt', 'reserve', 'not_yet', 'not_applicable'];
}
