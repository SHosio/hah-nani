import { useState } from "react";

const ruleCards = [
  // PASSIVE
  { type:"rule", form:"Passive", front:"Passive: る-verb rule", frontSub:"受身形・る動詞", back:"る → られる", backRomaji:"ru → rareru", example:"食べる → 食べられる", exampleRomaji:"taberu → taberareru", exampleEn:"to eat → to be eaten" },
  { type:"rule", form:"Passive", front:"Passive: う-verb rule", frontSub:"受身形・う動詞", back:"u-sound → a-sound + れる", backRomaji:"u → a + reru", example:"書く→書かれる　飲む→飲まれる", exampleRomaji:"kaku→kakareru  nomu→nomareru", exampleEn:"write→be written  drink→be drunk" },
  { type:"rule", form:"Passive", front:"Passive: irregulars", frontSub:"受身形・不規則動詞", back:"する→される　くる→こられる", backRomaji:"suru→sareru  kuru→korareru", example:"宿題がされた。", exampleRomaji:"Shukudai ga sareta.", exampleEn:"The homework was done." },
  { type:"rule", form:"Passive", front:"Passive: nuance", frontSub:"受身形・ニュアンス", back:"Often implies inconvenience or suffering", backRomaji:"", example:"雨に降られた。", exampleRomaji:"Ame ni furareta.", exampleEn:"I got rained on. (inconvenience)" },
  // POTENTIAL
  { type:"rule", form:"Potential", front:"Potential: る-verb rule", frontSub:"可能形・る動詞", back:"る → られる", backRomaji:"ru → rareru", example:"見る→見られる　起きる→起きられる", exampleRomaji:"miru→mirareru  okiru→okirareru", exampleEn:"see→can see  wake→can wake up" },
  { type:"rule", form:"Potential", front:"Potential: う-verb rule", frontSub:"可能形・う動詞", back:"u-sound → e-sound + る", backRomaji:"u → e + ru", example:"書く→書ける　飲む→飲める", exampleRomaji:"kaku→kakeru  nomu→nomeru", exampleEn:"write→can write  drink→can drink" },
  { type:"rule", form:"Potential", front:"Potential: irregulars", frontSub:"可能形・不規則動詞", back:"する→できる　くる→こられる", backRomaji:"suru→dekiru  kuru→korareru", example:"日本語ができる。", exampleRomaji:"Nihongo ga dekiru.", exampleEn:"I can do/speak Japanese." },
  { type:"rule", form:"Potential", front:"⚠️ Passive vs Potential trap", frontSub:"受身形 vs 可能形", back:"る-verbs look IDENTICAL — context decides!", backRomaji:"", example:"食べられる = can eat OR is eaten", exampleRomaji:"taberareru", exampleEn:"う-verbs differ: 飲める≠飲まれる" },
  // CAUSATIVE
  { type:"rule", form:"Causative", front:"Causative: る-verb rule", frontSub:"使役形・る動詞", back:"る → させる", backRomaji:"ru → saseru", example:"食べる→食べさせる　見る→見させる", exampleRomaji:"taberu→tabesaseru  miru→misaseru", exampleEn:"eat→make eat  see→make see" },
  { type:"rule", form:"Causative", front:"Causative: う-verb rule", frontSub:"使役形・う動詞", back:"u-sound → a-sound + せる", backRomaji:"u → a + seru", example:"書く→書かせる　飲む→飲ませる", exampleRomaji:"kaku→kakaseru  nomu→nomaseru", exampleEn:"write→make write  drink→make drink" },
  { type:"rule", form:"Causative", front:"Causative: irregulars", frontSub:"使役形・不規則動詞", back:"する→させる　くる→こさせる", backRomaji:"suru→saseru  kuru→kosaseru", example:"掃除をさせた。", exampleRomaji:"Souji wo saseta.", exampleEn:"I made them clean." },
  { type:"rule", form:"Causative", front:"Causative: make vs let", frontSub:"強制 vs 許可", back:"Same form! Context decides forced or permitted.", backRomaji:"", example:"野菜を食べさせた (forced) vs 好きな物を食べさせた (permitted)", exampleRomaji:"", exampleEn:"Both use 食べさせる" },
  // CAUSATIVE-PASSIVE
  { type:"rule", form:"Causative-Passive", front:"Causative-Passive: meaning", frontSub:"使役受身形・意味", back:"\"was made to do\" — unwilling suffering", backRomaji:"", example:"野菜を食べさせられた。", exampleRomaji:"Yasai wo tabesaserareta.", exampleEn:"I was made to eat vegetables." },
  { type:"rule", form:"Causative-Passive", front:"Causative-Passive: る-verb rule", frontSub:"使役受身形・る動詞", back:"る → させられる", backRomaji:"ru → saserareru", example:"食べる → 食べさせられる", exampleRomaji:"taberu → tabesaserareru", exampleEn:"eat → be made to eat" },
  { type:"rule", form:"Causative-Passive", front:"Causative-Passive: う-verb rule", frontSub:"使役受身形・う動詞", back:"a-sound + せられる OR short form: a + される", backRomaji:"a + serareru OR a + sareru", example:"飲む→飲まされる　書く→書かされる", exampleRomaji:"nomu→nomasareru  kaku→kakasareru", exampleEn:"Short form common in casual speech" },
  { type:"rule", form:"Causative-Passive", front:"Causative-Passive: irregulars", frontSub:"使役受身形・不規則動詞", back:"する→させられる　くる→こさせられる", backRomaji:"suru→saserareru  kuru→kosaserareru", example:"残業をさせられた。", exampleRomaji:"Zangyou wo saserareta.", exampleEn:"I was made to do overtime." },
  { type:"rule", form:"Causative-Passive", front:"Causative-Passive: who does it?", frontSub:"使役受身形・構造", back:"[person] は [authority] に [verb]させられる", backRomaji:"", example:"私は上司に残業させられた。", exampleRomaji:"Watashi wa joushi ni zangyou saserareta.", exampleEn:"I was made to do overtime by my boss." },
  // VOLITIONAL
  { type:"rule", form:"Volitional", front:"Volitional: る-verb rule", frontSub:"意向形・る動詞", back:"る→よう (plain)　ます→ましょう (polite)", backRomaji:"ru→you  masu→mashou", example:"食べる→食べよう / 食べましょう", exampleRomaji:"taberu→tabeyou / tabemashou", exampleEn:"Let's eat / Shall we eat" },
  { type:"rule", form:"Volitional", front:"Volitional: う-verb rule", frontSub:"意向形・う動詞", back:"u-sound → o-sound + う", backRomaji:"u → o + u", example:"書く→書こう　飲む→飲もう　行く→行こう", exampleRomaji:"kaku→kakou  nomu→nomou  iku→ikou", exampleEn:"Let's write / drink / go" },
  { type:"rule", form:"Volitional", front:"Volitional: irregulars", frontSub:"意向形・不規則動詞", back:"する→しよう　くる→こよう", backRomaji:"suru→shiyou  kuru→koyou", example:"勉強しよう！一緒に来よう！", exampleRomaji:"Benkyou shiyou!  Issho ni koyou!", exampleEn:"Let's study! Come together!" },
  { type:"rule", form:"Volitional", front:"Volitional: 3 key uses", frontSub:"意向形・使い方", back:"① Let's ~  ② I think I'll ~  ③ よう+とする = try to ~", backRomaji:"", example:"行こうとしたが、雨が降った。", exampleRomaji:"Ikou to shita ga, ame ga futta.", exampleEn:"I tried to go, but it rained." },
];

const verbCards = [
  // ── PASSIVE ──
  { type:"verb", form:"Passive", verb:"食べる", romaji:"taberu", answer:"食べられる", answerRomaji:"taberareru", example:"ケーキが食べられた。", exampleRomaji:"Keeki ga taberareta.", exampleEn:"The cake was eaten." },
  { type:"verb", form:"Passive", verb:"飲む", romaji:"nomu", answer:"飲まれる", answerRomaji:"nomareru", example:"ビールが全部飲まれた。", exampleRomaji:"Biiru ga zenbu nomareta.", exampleEn:"All the beer was drunk." },
  { type:"verb", form:"Passive", verb:"書く", romaji:"kaku", answer:"書かれる", answerRomaji:"kakareru", example:"手紙が書かれた。", exampleRomaji:"Tegami ga kakareta.", exampleEn:"The letter was written." },
  { type:"verb", form:"Passive", verb:"読む", romaji:"yomu", answer:"読まれる", answerRomaji:"yomareru", example:"本が読まれた。", exampleRomaji:"Hon ga yomareta.", exampleEn:"The book was read." },
  { type:"verb", form:"Passive", verb:"する", romaji:"suru", answer:"される", answerRomaji:"sareru", example:"準備がされた。", exampleRomaji:"Junbi ga sareta.", exampleEn:"The preparations were done." },
  { type:"verb", form:"Passive", verb:"聞く", romaji:"kiku", answer:"聞かれる", answerRomaji:"kikareru", example:"質問を聞かれた。", exampleRomaji:"Shitsumon wo kikareta.", exampleEn:"I was asked a question." },
  { type:"verb", form:"Passive", verb:"怒る", romaji:"okoru", answer:"怒られる", answerRomaji:"okorareru", example:"先生に怒られた。", exampleRomaji:"Sensei ni okorareta.", exampleEn:"I was scolded by the teacher." },
  { type:"verb", form:"Passive", verb:"盗む", romaji:"nusumu", answer:"盗まれる", answerRomaji:"nusumareru", example:"財布を盗まれた。", exampleRomaji:"Saifu wo nusumareta.", exampleEn:"My wallet was stolen." },
  { type:"verb", form:"Passive", verb:"踏む", romaji:"fumu", answer:"踏まれる", answerRomaji:"fumareru", example:"電車で足を踏まれた。", exampleRomaji:"Densha de ashi wo fumareta.", exampleEn:"My foot was stepped on in the train." },
  { type:"verb", form:"Passive", verb:"笑う", romaji:"warau", answer:"笑われる", answerRomaji:"warawareru", example:"みんなに笑われた。", exampleRomaji:"Minna ni warawareta.", exampleEn:"I was laughed at by everyone." },
  { type:"verb", form:"Passive", verb:"呼ぶ", romaji:"yobu", answer:"呼ばれる", answerRomaji:"yobareru", example:"名前を呼ばれた。", exampleRomaji:"Namae wo yobareta.", exampleEn:"My name was called." },
  { type:"verb", form:"Passive", verb:"褒める", romaji:"homeru", answer:"褒められる", answerRomaji:"homerareru", example:"先生に褒められた。", exampleRomaji:"Sensei ni homerareta.", exampleEn:"I was praised by the teacher." },
  { type:"verb", form:"Passive", verb:"頼む", romaji:"tanomu", answer:"頼まれる", answerRomaji:"tanomareru", example:"仕事を頼まれた。", exampleRomaji:"Shigoto wo tanomareta.", exampleEn:"I was asked to do the job." },
  { type:"verb", form:"Passive", verb:"起こす", romaji:"okosu", answer:"起こされる", answerRomaji:"okosarereru", example:"朝早く起こされた。", exampleRomaji:"Asa hayaku okosareta.", exampleEn:"I was woken up early." },
  { type:"verb", form:"Passive", verb:"くる", romaji:"kuru", answer:"こられる", answerRomaji:"korareru", example:"急に来られて困った。", exampleRomaji:"Kyuu ni korarete komatta.", exampleEn:"I was troubled by them coming suddenly." },
  { type:"verb", form:"Passive", verb:"押す", romaji:"osu", answer:"押される", answerRomaji:"osareru", example:"電車の中で押された。", exampleRomaji:"Densha no naka de osareta.", exampleEn:"I was pushed on the train." },
  { type:"verb", form:"Passive", verb:"叱る", romaji:"shikaru", answer:"叱られる", answerRomaji:"shikarareru", example:"上司に叱られた。", exampleRomaji:"Joushi ni shikarareta.", exampleEn:"I was scolded by my boss." },
  { type:"verb", form:"Passive", verb:"使う", romaji:"tsukau", answer:"使われる", answerRomaji:"tsukawareru", example:"道具として使われた。", exampleRomaji:"Dougu to shite tsukawareta.", exampleEn:"I was used as a tool." },
  // ── POTENTIAL ──
  { type:"verb", form:"Potential", verb:"食べる", romaji:"taberu", answer:"食べられる", answerRomaji:"taberareru", example:"辛い物が食べられる。", exampleRomaji:"Karai mono ga taberareru.", exampleEn:"I can eat spicy food." },
  { type:"verb", form:"Potential", verb:"飲む", romaji:"nomu", answer:"飲める", answerRomaji:"nomeru", example:"お酒が飲める。", exampleRomaji:"Osake ga nomeru.", exampleEn:"I can drink alcohol." },
  { type:"verb", form:"Potential", verb:"書く", romaji:"kaku", answer:"書ける", answerRomaji:"kakeru", example:"漢字が書ける。", exampleRomaji:"Kanji ga kakeru.", exampleEn:"I can write kanji." },
  { type:"verb", form:"Potential", verb:"読む", romaji:"yomu", answer:"読める", answerRomaji:"yomeru", example:"日本語が読める。", exampleRomaji:"Nihongo ga yomeru.", exampleEn:"I can read Japanese." },
  { type:"verb", form:"Potential", verb:"する", romaji:"suru", answer:"できる", answerRomaji:"dekiru", example:"日本語ができる。", exampleRomaji:"Nihongo ga dekiru.", exampleEn:"I can do Japanese." },
  { type:"verb", form:"Potential", verb:"くる", romaji:"kuru", answer:"こられる", answerRomaji:"korareru", example:"明日来られる？", exampleRomaji:"Ashita korareru?", exampleEn:"Can you come tomorrow?" },
  { type:"verb", form:"Potential", verb:"話す", romaji:"hanasu", answer:"話せる", answerRomaji:"hanaseru", example:"英語が話せる。", exampleRomaji:"Eigo ga hanaseru.", exampleEn:"I can speak English." },
  { type:"verb", form:"Potential", verb:"見る", romaji:"miru", answer:"見られる", answerRomaji:"mirareru", example:"富士山が見られる。", exampleRomaji:"Fujisan ga mirareru.", exampleEn:"You can see Mt. Fuji." },
  { type:"verb", form:"Potential", verb:"泳ぐ", romaji:"oyogu", answer:"泳げる", answerRomaji:"oyogeru", example:"海で泳げる。", exampleRomaji:"Umi de oyogeru.", exampleEn:"I can swim in the sea." },
  { type:"verb", form:"Potential", verb:"起きる", romaji:"okiru", answer:"起きられる", answerRomaji:"okirareru", example:"六時に起きられる。", exampleRomaji:"Rokuji ni okirareru.", exampleEn:"I can wake up at 6." },
  { type:"verb", form:"Potential", verb:"使う", romaji:"tsukau", answer:"使える", answerRomaji:"tsukaeru", example:"魔法が使える。", exampleRomaji:"Mahou ga tsukaeru.", exampleEn:"I can use magic." },
  { type:"verb", form:"Potential", verb:"待つ", romaji:"matsu", answer:"待てる", answerRomaji:"materu", example:"もう待てない。", exampleRomaji:"Mou matenai.", exampleEn:"I can't wait any longer." },
  { type:"verb", form:"Potential", verb:"買う", romaji:"kau", answer:"買える", answerRomaji:"kaeru", example:"この店で何でも買える。", exampleRomaji:"Kono mise de nandemo kaeru.", exampleEn:"You can buy anything here." },
  { type:"verb", form:"Potential", verb:"寝る", romaji:"neru", answer:"寝られる", answerRomaji:"nerareru", example:"うるさくて寝られない。", exampleRomaji:"Urusaku te nerarenai.", exampleEn:"It's noisy so I can't sleep." },
  { type:"verb", form:"Potential", verb:"行く", romaji:"iku", answer:"行ける", answerRomaji:"ikeru", example:"明日パーティーに行ける。", exampleRomaji:"Ashita paatii ni ikeru.", exampleEn:"I can go to the party tomorrow." },
  { type:"verb", form:"Potential", verb:"聞く", romaji:"kiku", answer:"聞ける", answerRomaji:"kikeru", example:"ここで音楽が聞ける。", exampleRomaji:"Koko de ongaku ga kikeru.", exampleEn:"You can listen to music here." },
  { type:"verb", form:"Potential", verb:"運転する", romaji:"unten suru", answer:"運転できる", answerRomaji:"unten dekiru", example:"車が運転できる。", exampleRomaji:"Kuruma ga unten dekiru.", exampleEn:"I can drive a car." },
  // ── CAUSATIVE ──
  { type:"verb", form:"Causative", verb:"食べる", romaji:"taberu", answer:"食べさせる", answerRomaji:"tabesaseru", example:"子供に野菜を食べさせた。", exampleRomaji:"Kodomo ni yasai wo tabesaseta.", exampleEn:"I made the child eat vegetables." },
  { type:"verb", form:"Causative", verb:"飲む", romaji:"nomu", answer:"飲ませる", answerRomaji:"nomaseru", example:"薬を飲ませた。", exampleRomaji:"Kusuri wo nomaseta.", exampleEn:"I made them drink the medicine." },
  { type:"verb", form:"Causative", verb:"書く", romaji:"kaku", answer:"書かせる", answerRomaji:"kakaseru", example:"学生にレポートを書かせた。", exampleRomaji:"Gakusei ni repooto wo kakaseta.", exampleEn:"I made the students write a report." },
  { type:"verb", form:"Causative", verb:"読む", romaji:"yomu", answer:"読ませる", answerRomaji:"yomaseru", example:"本を読ませた。", exampleRomaji:"Hon wo yomaseta.", exampleEn:"I made them read the book." },
  { type:"verb", form:"Causative", verb:"する", romaji:"suru", answer:"させる", answerRomaji:"saseru", example:"掃除をさせた。", exampleRomaji:"Souji wo saseta.", exampleEn:"I made them clean." },
  { type:"verb", form:"Causative", verb:"くる", romaji:"kuru", answer:"こさせる", answerRomaji:"kosaseru", example:"早く来させた。", exampleRomaji:"Hayaku kosaseta.", exampleEn:"I made them come early." },
  { type:"verb", form:"Causative", verb:"聞く", romaji:"kiku", answer:"聞かせる", answerRomaji:"kikaseru", example:"音楽を聞かせた。", exampleRomaji:"Ongaku wo kikaseta.", exampleEn:"I let them listen to music." },
  { type:"verb", form:"Causative", verb:"待つ", romaji:"matsu", answer:"待たせる", answerRomaji:"mataseru", example:"長く待たせてすみません。", exampleRomaji:"Nagaku matasete sumimasen.", exampleEn:"Sorry for making you wait." },
  { type:"verb", form:"Causative", verb:"泣く", romaji:"naku", answer:"泣かせる", answerRomaji:"nakaseru", example:"映画が私を泣かせた。", exampleRomaji:"Eiga ga watashi wo nakaseta.", exampleEn:"The movie made me cry." },
  { type:"verb", form:"Causative", verb:"笑う", romaji:"warau", answer:"笑わせる", answerRomaji:"warawaseru", example:"友だちを笑わせた。", exampleRomaji:"Tomodachi wo warawaseta.", exampleEn:"I made my friend laugh." },
  { type:"verb", form:"Causative", verb:"働く", romaji:"hataraku", answer:"働かせる", answerRomaji:"hatarakaseru", example:"社員を遅くまで働かせた。", exampleRomaji:"Shain wo osoku made hatarakaseta.", exampleEn:"I made employees work late." },
  { type:"verb", form:"Causative", verb:"行く", romaji:"iku", answer:"行かせる", answerRomaji:"ikaseru", example:"子供を学校に行かせた。", exampleRomaji:"Kodomo wo gakkou ni ikaseta.", exampleEn:"I made the child go to school." },
  { type:"verb", form:"Causative", verb:"話す", romaji:"hanasu", answer:"話させる", answerRomaji:"hanasaseru", example:"学生を発表させた。", exampleRomaji:"Gakusei wo happyou saseta.", exampleEn:"I made the student present." },
  { type:"verb", form:"Causative", verb:"見る", romaji:"miru", answer:"見させる", answerRomaji:"misaseru", example:"映画を見させてください。", exampleRomaji:"Eiga wo misasete kudasai.", exampleEn:"Please let me watch the movie." },
  { type:"verb", form:"Causative", verb:"帰る", romaji:"kaeru", answer:"帰らせる", answerRomaji:"kaeraseru", example:"早く帰らせてください。", exampleRomaji:"Hayaku kaerasete kudasai.", exampleEn:"Please let me go home early." },
  { type:"verb", form:"Causative", verb:"座る", romaji:"suwaru", answer:"座らせる", answerRomaji:"suwaraseru", example:"子供を座らせた。", exampleRomaji:"Kodomo wo suwaraseta.", exampleEn:"I made the child sit down." },
  // ── CAUSATIVE-PASSIVE ──
  { type:"verb", form:"Causative-Passive", verb:"食べる", romaji:"taberu", answer:"食べさせられる", answerRomaji:"tabesaserareru", example:"野菜を食べさせられた。", exampleRomaji:"Yasai wo tabesaserareta.", exampleEn:"I was made to eat vegetables." },
  { type:"verb", form:"Causative-Passive", verb:"飲む", romaji:"nomu", answer:"飲まされる", answerRomaji:"nomasareru", example:"お酒を飲まされた。", exampleRomaji:"Osake wo nomasareta.", exampleEn:"I was made to drink alcohol." },
  { type:"verb", form:"Causative-Passive", verb:"書く", romaji:"kaku", answer:"書かされる", answerRomaji:"kakasareru", example:"長いレポートを書かされた。", exampleRomaji:"Nagai repooto wo kakasareta.", exampleEn:"I was made to write a long report." },
  { type:"verb", form:"Causative-Passive", verb:"読む", romaji:"yomu", answer:"読まされる", answerRomaji:"yomasareru", example:"つまらない本を読まされた。", exampleRomaji:"Tsumaranai hon wo yomasareta.", exampleEn:"I was made to read a boring book." },
  { type:"verb", form:"Causative-Passive", verb:"する", romaji:"suru", answer:"させられる", answerRomaji:"saserareru", example:"残業をさせられた。", exampleRomaji:"Zangyou wo saserareta.", exampleEn:"I was made to do overtime." },
  { type:"verb", form:"Causative-Passive", verb:"待つ", romaji:"matsu", answer:"待たされる", answerRomaji:"matasareru", example:"一時間待たされた。", exampleRomaji:"Ichijikan matasareta.", exampleEn:"I was made to wait an hour." },
  { type:"verb", form:"Causative-Passive", verb:"歌う", romaji:"utau", answer:"歌わされる", answerRomaji:"utawasareru", example:"カラオケで歌わされた。", exampleRomaji:"Karaoke de utawasareta.", exampleEn:"I was made to sing at karaoke." },
  { type:"verb", form:"Causative-Passive", verb:"働く", romaji:"hataraku", answer:"働かされる", answerRomaji:"hatarakasareru", example:"毎日遅くまで働かされた。", exampleRomaji:"Mainichi osoku made hatarakasareta.", exampleEn:"I was made to work late every day." },
  { type:"verb", form:"Causative-Passive", verb:"行く", romaji:"iku", answer:"行かされる", answerRomaji:"ikasareru", example:"出張に行かされた。", exampleRomaji:"Shucchou ni ikasareta.", exampleEn:"I was made to go on a business trip." },
  { type:"verb", form:"Causative-Passive", verb:"話す", romaji:"hanasu", answer:"話させられる", answerRomaji:"hanasaserareru", example:"スピーチをさせられた。", exampleRomaji:"Supiichu wo saserareta.", exampleEn:"I was made to give a speech." },
  { type:"verb", form:"Causative-Passive", verb:"掃除する", romaji:"souji suru", answer:"掃除させられる", answerRomaji:"souji saserareru", example:"一人で掃除させられた。", exampleRomaji:"Hitori de souji saserareta.", exampleEn:"I was made to clean alone." },
  { type:"verb", form:"Causative-Passive", verb:"泣く", romaji:"naku", answer:"泣かされる", answerRomaji:"nakasareru", example:"その映画に泣かされた。", exampleRomaji:"Sono eiga ni nakasareta.", exampleEn:"I was made to cry by that movie." },
  { type:"verb", form:"Causative-Passive", verb:"くる", romaji:"kuru", answer:"こさせられる", answerRomaji:"kosaserareru", example:"早朝に来させられた。", exampleRomaji:"Souchou ni kosaserareta.", exampleEn:"I was made to come at dawn." },
  { type:"verb", form:"Causative-Passive", verb:"買う", romaji:"kau", answer:"買わされる", answerRomaji:"kawasareru", example:"高い物を買わされた。", exampleRomaji:"Takai mono wo kawasareta.", exampleEn:"I was made to buy expensive things." },
  { type:"verb", form:"Causative-Passive", verb:"走る", romaji:"hashiru", answer:"走らされる", answerRomaji:"hashirasareru", example:"グラウンドを走らされた。", exampleRomaji:"Guraundo wo hashirasareta.", exampleEn:"I was made to run the field." },
  // ── VOLITIONAL ──
  { type:"verb", form:"Volitional", verb:"食べる", romaji:"taberu", answer:"食べよう", answerRomaji:"tabeyou", example:"一緒に食べよう！", exampleRomaji:"Issho ni tabeyou!", exampleEn:"Let's eat together!" },
  { type:"verb", form:"Volitional", verb:"飲む", romaji:"nomu", answer:"飲もう", answerRomaji:"nomou", example:"コーヒーを飲もう。", exampleRomaji:"Koohii wo nomou.", exampleEn:"Let's drink coffee." },
  { type:"verb", form:"Volitional", verb:"書く", romaji:"kaku", answer:"書こう", answerRomaji:"kakou", example:"手紙を書こう。", exampleRomaji:"Tegami wo kakou.", exampleEn:"Let's write a letter." },
  { type:"verb", form:"Volitional", verb:"読む", romaji:"yomu", answer:"読もう", answerRomaji:"yomou", example:"この本を読もう。", exampleRomaji:"Kono hon wo yomou.", exampleEn:"Let's read this book." },
  { type:"verb", form:"Volitional", verb:"する", romaji:"suru", answer:"しよう", answerRomaji:"shiyou", example:"勉強しよう！", exampleRomaji:"Benkyou shiyou!", exampleEn:"Let's study!" },
  { type:"verb", form:"Volitional", verb:"くる", romaji:"kuru", answer:"こよう", answerRomaji:"koyou", example:"一緒に来よう。", exampleRomaji:"Issho ni koyou.", exampleEn:"Let's come together." },
  { type:"verb", form:"Volitional", verb:"行く", romaji:"iku", answer:"行こう", answerRomaji:"ikou", example:"東京に行こう！", exampleRomaji:"Tokyo ni ikou!", exampleEn:"Let's go to Tokyo!" },
  { type:"verb", form:"Volitional", verb:"見る", romaji:"miru", answer:"見よう", answerRomaji:"miyou", example:"映画を見よう。", exampleRomaji:"Eiga wo miyou.", exampleEn:"Let's watch a movie." },
  { type:"verb", form:"Volitional", verb:"話す", romaji:"hanasu", answer:"話そう", answerRomaji:"hanasou", example:"後で話そう。", exampleRomaji:"Ato de hanasou.", exampleEn:"Let's talk later." },
  { type:"verb", form:"Volitional", verb:"待つ", romaji:"matsu", answer:"待とう", answerRomaji:"matou", example:"ここで待とう。", exampleRomaji:"Koko de matou.", exampleEn:"Let's wait here." },
  { type:"verb", form:"Volitional", verb:"帰る", romaji:"kaeru", answer:"帰ろう", answerRomaji:"kaerou", example:"もう帰ろう。", exampleRomaji:"Mou kaerou.", exampleEn:"Let's go home already." },
  { type:"verb", form:"Volitional", verb:"寝る", romaji:"neru", answer:"寝よう", answerRomaji:"neyou", example:"早く寝よう。", exampleRomaji:"Hayaku neyou.", exampleEn:"Let's sleep early." },
  { type:"verb", form:"Volitional", verb:"始める", romaji:"hajimeru", answer:"始めよう", answerRomaji:"hajimeyou", example:"さあ、始めよう！", exampleRomaji:"Saa, hajimeyou!", exampleEn:"Alright, let's begin!" },
  { type:"verb", form:"Volitional", verb:"頑張る", romaji:"ganbaru", answer:"頑張ろう", answerRomaji:"ganbarou", example:"一緒に頑張ろう！", exampleRomaji:"Issho ni ganbarou!", exampleEn:"Let's do our best together!" },
  { type:"verb", form:"Volitional", verb:"考える", romaji:"kangaeru", answer:"考えよう", answerRomaji:"kangaeyou", example:"もっと考えよう。", exampleRomaji:"Motto kangaeyou.", exampleEn:"Let's think more." },
  { type:"verb", form:"Volitional", verb:"休む", romaji:"yasumu", answer:"休もう", answerRomaji:"yasumou", example:"少し休もう。", exampleRomaji:"Sukoshi yasumou.", exampleEn:"Let's rest a little." },
  { type:"verb", form:"Volitional", verb:"聞く", romaji:"kiku", answer:"聞こう", answerRomaji:"kikou", example:"音楽を聞こう。", exampleRomaji:"Ongaku wo kikou.", exampleEn:"Let's listen to music." },
  { type:"verb", form:"Volitional", verb:"遊ぶ", romaji:"asobu", answer:"遊ぼう", answerRomaji:"asobou", example:"公園で遊ぼう。", exampleRomaji:"Kouen de asobou.", exampleEn:"Let's play at the park." },
  { type:"verb", form:"Volitional", verb:"諦める", romaji:"akirameru", answer:"諦めよう", answerRomaji:"akirameyou", example:"もう諦めよう。", exampleRomaji:"Mou akirameyou.", exampleEn:"Let's give up already." },
];

const allCards = [...ruleCards, ...verbCards];

const formAccents = {
  "Passive":            "#e94560",
  "Potential":          "#4fc3f7",
  "Causative":          "#f5a623",
  "Causative-Passive":  "#a78bfa",
  "Volitional":         "#34d399",
};
const formLabels = {
  "Passive":            "受身形",
  "Potential":          "可能形",
  "Causative":          "使役形",
  "Causative-Passive":  "使役受身形",
  "Volitional":         "意向形",
};

function shuffle(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

export default function Flashcards() {
  const [filter, setFilter] = useState("All");
  const [typeFilter, setTypeFilter] = useState("All");
  const [deck, setDeck] = useState(() => shuffle(allCards));
  const [index, setIndex] = useState(0);
  const [flipped, setFlipped] = useState(false);
  const [score, setScore] = useState({ correct: 0, incorrect: 0 });
  const [done, setDone] = useState(false);

  const getFiltered = (d = deck, f = filter, t = typeFilter) => {
    let res = d;
    if (f !== "All") res = res.filter(c => c.form === f);
    if (t === "Rules") res = res.filter(c => c.type === "rule");
    if (t === "Verbs") res = res.filter(c => c.type === "verb");
    return res;
  };

  const filtered = getFiltered();
  const card = filtered[index];
  const accent = card ? formAccents[card.form] : "#fff";
  const total = filtered.length;

  const go = (dir) => {
    setFlipped(false);
    setTimeout(() => {
      const next = index + dir;
      if (next >= total) setDone(true);
      else setIndex(Math.max(0, next));
    }, 160);
  };

  const mark = (correct) => {
    setScore(s => ({ ...s, [correct ? "correct" : "incorrect"]: s[correct ? "correct" : "incorrect"] + 1 }));
    go(1);
  };

  const reset = (f, t) => {
    const nf = f ?? filter; const nt = t ?? typeFilter;
    const nd = shuffle(allCards);
    setFilter(nf); setTypeFilter(nt); setDeck(nd);
    setIndex(0); setFlipped(false); setDone(false);
    setScore({ correct: 0, incorrect: 0 });
  };

  const counts = Object.keys(formAccents).reduce((acc, f) => {
    acc[f] = allCards.filter(c => c.form === f).length;
    return acc;
  }, {});

  return (
    <div style={{ minHeight:"100vh", background:"#080810", display:"flex", flexDirection:"column", alignItems:"center", fontFamily:"'Noto Serif JP',Georgia,serif", padding:"24px 16px 48px" }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+JP:wght@400;700&family=Space+Mono:wght@400;700&display=swap');
        *{box-sizing:border-box;margin:0;padding:0;}
        .ci{transition:transform .45s cubic-bezier(.4,2,.55,1);transform-style:preserve-3d;width:100%;height:100%;}
        .fl{transform:rotateY(180deg);}
        .fc{position:absolute;width:100%;height:100%;backface-visibility:hidden;-webkit-backface-visibility:hidden;border-radius:18px;}
        .bk{transform:rotateY(180deg);}
        .btn{cursor:pointer;border:none;border-radius:10px;font-family:'Space Mono',monospace;transition:all .18s;}
        .btn:hover{transform:translateY(-2px);opacity:.85;}
        .btn:disabled{cursor:default;}
        .btn:disabled:hover{transform:none;}
        .pill{cursor:pointer;border:none;border-radius:20px;font-family:'Space Mono',monospace;transition:all .18s;}
        .pill:hover{transform:translateY(-1px);}
      `}</style>

      {/* Header */}
      <div style={{ textAlign:"center", marginBottom:20 }}>
        <div style={{ color:"#888", fontFamily:"'Space Mono',monospace", fontSize:11, letterSpacing:4, marginBottom:6 }}>GENKI II · 第22課</div>
        <h1 style={{ color:"#fff", fontSize:22, fontWeight:700, letterSpacing:2, marginBottom:4 }}>文法フラッシュカード</h1>
        <div style={{ color:"#888", fontFamily:"'Space Mono',monospace", fontSize:11, letterSpacing:3 }}>GRAMMAR FLASHCARDS · {allCards.length} CARDS</div>
      </div>

      {/* Form filter */}
      <div style={{ display:"flex", gap:6, marginBottom:7, flexWrap:"wrap", justifyContent:"center" }}>
        {["All","Passive","Potential","Causative","Causative-Passive","Volitional"].map(f => (
          <button key={f} className="pill" onClick={() => reset(f, typeFilter)} style={{
            background: filter===f ? (f==="All"?"#fff":formAccents[f]) : "#1c1c32",
            color: filter===f ? "#080810" : "#aaa",
            border:`1px solid ${filter===f?"transparent":"#2e2e50"}`,
            fontWeight: filter===f?700:400, fontSize:11, padding:"6px 13px",
          }}>{f==="Causative-Passive"?"Caus-Pass":f}</button>
        ))}
      </div>

      {/* Type filter */}
      <div style={{ display:"flex", gap:6, marginBottom:20 }}>
        {["All","Rules","Verbs"].map(t => (
          <button key={t} className="pill" onClick={() => reset(filter, t)} style={{
            background: typeFilter===t?"#fff":"#1c1c32",
            color: typeFilter===t?"#080810":"#aaa",
            border:`1px solid ${typeFilter===t?"transparent":"#2e2e50"}`,
            fontWeight: typeFilter===t?700:400, fontSize:11, padding:"5px 14px",
          }}>{t}</button>
        ))}
      </div>

      {/* Progress */}
      {!done && card && (
        <div style={{ width:"100%", maxWidth:440, marginBottom:14 }}>
          <div style={{ display:"flex", justifyContent:"space-between", marginBottom:6 }}>
            <span style={{ fontFamily:"'Space Mono',monospace", fontSize:13, color:"#aaa" }}>{index+1} / {total}</span>
            <div style={{ display:"flex", gap:12 }}>
              <span style={{ fontFamily:"'Space Mono',monospace", fontSize:13, color:"#34d399" }}>✓ {score.correct}</span>
              <span style={{ fontFamily:"'Space Mono',monospace", fontSize:13, color:"#e94560" }}>✗ {score.incorrect}</span>
            </div>
          </div>
          <div style={{ height:3, background:"#2a2a45", borderRadius:2 }}>
            <div style={{ height:"100%", width:`${(index/total)*100}%`, background:accent, borderRadius:2, transition:"width .4s" }} />
          </div>
        </div>
      )}

      {/* Done */}
      {done ? (
        <div style={{ textAlign:"center", color:"#fff", marginTop:40 }}>
          <div style={{ fontSize:52, marginBottom:16 }}>🎌</div>
          <div style={{ fontFamily:"'Space Mono',monospace", fontSize:12, color:"#aaa", letterSpacing:3, marginBottom:12 }}>SESSION COMPLETE</div>
          <div style={{ fontSize:44, fontWeight:700, marginBottom:8 }}>
            <span style={{ color:"#34d399" }}>{score.correct}</span>
            <span style={{ color:"#888" }}> / {score.correct+score.incorrect}</span>
          </div>
          <div style={{ color:"#bbb", fontSize:14, marginBottom:32, fontFamily:"'Space Mono',monospace" }}>
            {score.incorrect===0?"完璧！Perfect! 🎯":score.correct>score.incorrect?"よくできました！":"もう一回！Try again!"}
          </div>
          <button className="btn" onClick={() => reset()} style={{ background:"#fff", color:"#080810", fontWeight:700, fontSize:13, padding:"12px 28px" }}>
            Shuffle & Restart →
          </button>
        </div>
      ) : card ? (
        <>
          {/* Card */}
          <div style={{ perspective:1000, width:"100%", maxWidth:440, height: card.type==="rule"?350:310, marginBottom:16, cursor:"pointer" }} onClick={() => setFlipped(f=>!f)}>
            <div className={`ci ${flipped?"fl":""}`}>
              {/* Front */}
              <div className="fc" style={{ background:"#0c0c1e", border:`1px solid #2a2a45`, borderTop:`3px solid ${accent}`, display:"flex", flexDirection:"column", alignItems:"center", justifyContent:"center", padding:32, textAlign:"center" }}>
                {card.type==="rule" && (
                  <div style={{ marginBottom:10, fontFamily:"'Space Mono',monospace", fontSize:11, color:"#aaa", letterSpacing:2, border:"1px dashed #555", padding:"3px 12px", borderRadius:8 }}>RULE CARD</div>
                )}
                <div style={{ marginBottom:12 }}>
                  <span style={{ background:accent+"33", color:accent, padding:"3px 12px", borderRadius:12, fontFamily:"'Space Mono',monospace", fontSize:11, fontWeight:700, letterSpacing:1 }}>
                    {card.form} · {formLabels[card.form]}
                  </span>
                </div>
                {card.type==="verb" ? (
                  <>
                    <div style={{ fontSize:50, color:"#fff", marginBottom:8 }}>{card.verb}</div>
                    <div style={{ fontFamily:"'Space Mono',monospace", color:"#bbb", fontSize:15, marginBottom:20 }}>{card.romaji}</div>
                    <div style={{ fontFamily:"'Space Mono',monospace", color:accent, fontSize:12, letterSpacing:2 }}>→ {card.form.toUpperCase()} FORM?</div>
                  </>
                ) : (
                  <>
                    <div style={{ fontSize:17, color:"#fff", lineHeight:1.6, marginBottom:8 }}>{card.front}</div>
                    <div style={{ fontFamily:"'Space Mono',monospace", color:"#aaa", fontSize:12 }}>{card.frontSub}</div>
                  </>
                )}
                <div style={{ position:"absolute", bottom:16, fontFamily:"'Space Mono',monospace", color:"#555", fontSize:11, letterSpacing:2 }}>TAP TO REVEAL</div>
              </div>
              {/* Back */}
              <div className="fc bk" style={{ background:"#0c0c1e", border:`1px solid ${accent}66`, borderTop:`3px solid ${accent}`, display:"flex", flexDirection:"column", alignItems:"center", justifyContent:"center", padding:28, textAlign:"center" }}>
                {card.type==="verb" ? (
                  <>
                    <div style={{ fontSize:38, color:"#fff", marginBottom:6 }}>{card.answer}</div>
                    <div style={{ fontFamily:"'Space Mono',monospace", color:accent, fontSize:15, marginBottom:18 }}>{card.answerRomaji}</div>
                  </>
                ) : (
                  <>
                    <div style={{ fontSize:17, color:"#fff", lineHeight:1.6, marginBottom:card.backRomaji?6:18 }}>{card.back}</div>
                    {card.backRomaji && <div style={{ fontFamily:"'Space Mono',monospace", color:accent, fontSize:13, marginBottom:18 }}>{card.backRomaji}</div>}
                  </>
                )}
                <div style={{ width:"100%", borderTop:"1px solid #2a2a45", paddingTop:14 }}>
                  <div style={{ color:"#eee", fontSize:15, marginBottom:6, lineHeight:1.6 }}>{card.example}</div>
                  <div style={{ fontFamily:"'Space Mono',monospace", color:"#aaa", fontSize:12, marginBottom:5 }}>{card.exampleRomaji}</div>
                  <div style={{ color:"#777", fontSize:13, fontStyle:"italic" }}>{card.exampleEn}</div>
                </div>
              </div>
            </div>
          </div>

          {/* Buttons */}
          {flipped ? (
            <div style={{ display:"flex", gap:12, marginBottom:14 }}>
              <button className="btn" onClick={() => mark(false)} style={{ background:"#2a0d14", color:"#f87191", border:"1px solid #e9456066", fontSize:14, padding:"11px 26px", fontWeight:700 }}>✗ Again</button>
              <button className="btn" onClick={() => mark(true)} style={{ background:"#0d2a18", color:"#4ade80", border:"1px solid #34d39966", fontSize:14, padding:"11px 26px", fontWeight:700 }}>✓ Got it</button>
            </div>
          ) : (
            <div style={{ display:"flex", gap:10, marginBottom:14 }}>
              <button className="btn" onClick={() => go(-1)} disabled={index===0} style={{ background:"#1c1c32", color:index===0?"#3a3a5a":"#ccc", border:`1px solid ${index===0?"#2a2a45":"#4a4a70"}`, fontSize:13, padding:"10px 16px" }}>← Prev</button>
              <button className="btn" onClick={() => setFlipped(true)} style={{ background:accent, color:"#080810", fontWeight:700, fontSize:14, padding:"10px 24px" }}>Flip</button>
              <button className="btn" onClick={() => go(1)} style={{ background:"#1c1c32", color:"#ccc", border:"1px solid #4a4a70", fontSize:13, padding:"10px 16px" }}>Next →</button>
            </div>
          )}

          {/* Form legend */}
          <div style={{ display:"flex", gap:14, flexWrap:"wrap", justifyContent:"center", marginTop:4 }}>
            {Object.entries(formAccents).map(([f,c]) => (
              <div key={f} style={{ fontFamily:"'Space Mono',monospace", fontSize:11, color:"#777", display:"flex", alignItems:"center", gap:5 }}>
                <span style={{ width:7, height:7, borderRadius:"50%", background:c, display:"inline-block" }} />
                {f==="Causative-Passive"?"C-Pass":f} {counts[f]}
              </div>
            ))}
          </div>
        </>
      ) : (
        <div style={{ color:"#aaa", fontFamily:"'Space Mono',monospace", fontSize:13, marginTop:40 }}>No cards match this filter.</div>
      )}

      <div style={{ marginTop:32, fontFamily:"'Space Mono',monospace", fontSize:11, color:"#333", letterSpacing:2 }}>
        GENKI II · EDGE ACADEMIA · 日本語文法
      </div>
    </div>
  );
}
