-- Bangladesh upazilas/thanas (~490) — bd_districts (64 rows, chunk2.sql) and
-- bd_divisions (8 rows, chunk2.sql) were seeded, but bd_upazilas was never
-- populated, so District -> Thana selection had nothing to cascade into on
-- both Web and Flutter even though the reference-data API/UI were correct.
-- district_id values below match the existing bd_districts insertion order
-- (Barishal division districts 1-6, Chattogram 7-17, Dhaka 18-30, Khulna
-- 31-40, Mymensingh 41-44, Rajshahi 45-52, Rangpur 53-60, Sylhet 61-64).

INSERT INTO bd_upazilas (district_id, name, bn_name) VALUES
-- Barguna (1)
(1,'Amtali','আমতলী'),(1,'Bamna','বামনা'),(1,'Barguna Sadar','বরগুনা সদর'),(1,'Betagi','বেতাগী'),(1,'Patharghata','পাথরঘাটা'),(1,'Taltali','তালতলী'),
-- Barishal (2)
(2,'Agailjhara','আগৈলঝাড়া'),(2,'Babuganj','বাবুগঞ্জ'),(2,'Bakerganj','বাকেরগঞ্জ'),(2,'Banaripara','বানারীপাড়া'),(2,'Barishal Sadar','বরিশাল সদর'),(2,'Gaurnadi','গৌরনদী'),(2,'Hizla','হিজলা'),(2,'Mehendiganj','মেহেন্দিগঞ্জ'),(2,'Muladi','মুলাদী'),(2,'Wazirpur','উজিরপুর'),
-- Bhola (3)
(3,'Bhola Sadar','ভোলা সদর'),(3,'Burhanuddin','বোরহানউদ্দিন'),(3,'Char Fasson','চরফ্যাশন'),(3,'Daulatkhan','দৌলতখান'),(3,'Lalmohan','লালমোহন'),(3,'Manpura','মনপুরা'),(3,'Tazumuddin','তজুমদ্দিন'),
-- Jhalokati (4)
(4,'Jhalokati Sadar','ঝালকাঠি সদর'),(4,'Kathalia','কাঁঠালিয়া'),(4,'Nalchity','নলছিটি'),(4,'Rajapur','রাজাপুর'),
-- Patuakhali (5)
(5,'Bauphal','বাউফল'),(5,'Dashmina','দশমিনা'),(5,'Dumki','দুমকি'),(5,'Galachipa','গলাচিপা'),(5,'Kalapara','কলাপাড়া'),(5,'Mirzaganj','মির্জাগঞ্জ'),(5,'Patuakhali Sadar','পটুয়াখালী সদর'),(5,'Rangabali','রাঙ্গাবালী'),
-- Pirojpur (6)
(6,'Bhandaria','ভান্ডারিয়া'),(6,'Kawkhali','কাউখালী'),(6,'Mathbaria','মঠবাড়িয়া'),(6,'Nazirpur','নাজিরপুর'),(6,'Nesarabad','নেছারাবাদ'),(6,'Pirojpur Sadar','পিরোজপুর সদর'),(6,'Zianagar','জিয়ানগর'),
-- Bandarban (7)
(7,'Alikadam','আলীকদম'),(7,'Bandarban Sadar','বান্দরবান সদর'),(7,'Lama','লামা'),(7,'Naikhongchhari','নাইক্ষ্যংছড়ি'),(7,'Rowangchhari','রোয়াংছড়ি'),(7,'Ruma','রুমা'),(7,'Thanchi','থানচি'),
-- Brahmanbaria (8)
(8,'Akhaura','আখাউড়া'),(8,'Ashuganj','আশুগঞ্জ'),(8,'Bancharampur','বাঞ্ছারামপুর'),(8,'Bijoynagar','বিজয়নগর'),(8,'Brahmanbaria Sadar','ব্রাহ্মণবাড়িয়া সদর'),(8,'Kasba','কসবা'),(8,'Nabinagar','নবীনগর'),(8,'Nasirnagar','নাসিরনগর'),(8,'Sarail','সরাইল'),
-- Chandpur (9)
(9,'Chandpur Sadar','চাঁদপুর সদর'),(9,'Faridganj','ফরিদগঞ্জ'),(9,'Haimchar','হাইমচর'),(9,'Hajiganj','হাজীগঞ্জ'),(9,'Kachua','কচুয়া'),(9,'Matlab Dakshin','মতলব দক্ষিণ'),(9,'Matlab Uttar','মতলব উত্তর'),(9,'Shahrasti','শাহরাস্তি'),
-- Chattogram (10)
(10,'Anwara','আনোয়ারা'),(10,'Banshkhali','বাঁশখালী'),(10,'Boalkhali','বোয়ালখালী'),(10,'Chandanaish','চন্দনাইশ'),(10,'Fatikchhari','ফটিকছড়ি'),(10,'Hathazari','হাটহাজারী'),(10,'Lohagara','লোহাগাড়া'),(10,'Mirsharai','মীরসরাই'),(10,'Patiya','পটিয়া'),(10,'Rangunia','রাঙ্গুনিয়া'),(10,'Raozan','রাউজান'),(10,'Sandwip','সন্দ্বীপ'),(10,'Satkania','সাতকানিয়া'),(10,'Sitakunda','সীতাকুণ্ড'),
-- Cumilla (11)
(11,'Barura','বরুড়া'),(11,'Brahmanpara','ব্রাহ্মণপাড়া'),(11,'Burichang','বুড়িচং'),(11,'Chandina','চান্দিনা'),(11,'Chauddagram','চৌদ্দগ্রাম'),(11,'Daudkandi','দাউদকান্দি'),(11,'Debidwar','দেবিদ্বার'),(11,'Homna','হোমনা'),(11,'Laksam','লাকসাম'),(11,'Lalmai','লালমাই'),(11,'Meghna','মেঘনা'),(11,'Monohorgonj','মনোহরগঞ্জ'),(11,'Muradnagar','মুরাদনগর'),(11,'Nangalkot','নাঙ্গলকোট'),(11,'Cumilla Sadar','কুমিল্লা সদর'),(11,'Cumilla Sadar Dakshin','কুমিল্লা সদর দক্ষিণ'),(11,'Titas','তিতাস'),
-- Coxs Bazar (12)
(12,'Chakaria','চকরিয়া'),(12,'Coxs Bazar Sadar','কক্সবাজার সদর'),(12,'Kutubdia','কুতুবদিয়া'),(12,'Maheshkhali','মহেশখালী'),(12,'Pekua','পেকুয়া'),(12,'Ramu','রামু'),(12,'Teknaf','টেকনাফ'),(12,'Ukhia','উখিয়া'),
-- Feni (13)
(13,'Chhagalnaiya','ছাগলনাইয়া'),(13,'Daganbhuiyan','দাগনভূঞা'),(13,'Feni Sadar','ফেনী সদর'),(13,'Fulgazi','ফুলগাজী'),(13,'Parshuram','পরশুরাম'),(13,'Sonagazi','সোনাগাজী'),
-- Khagrachhari (14)
(14,'Dighinala','দিঘীনালা'),(14,'Khagrachhari Sadar','খাগড়াছড়ি সদর'),(14,'Lakshmichhari','লক্ষ্মীছড়ি'),(14,'Mahalchhari','মহালছড়ি'),(14,'Manikchhari','মানিকছড়ি'),(14,'Matiranga','মাটিরাঙ্গা'),(14,'Panchhari','পানছড়ি'),(14,'Ramgarh','রামগড়'),
-- Lakshmipur (15)
(15,'Kamalnagar','কমলনগর'),(15,'Lakshmipur Sadar','লক্ষ্মীপুর সদর'),(15,'Raipur','রায়পুর'),(15,'Ramganj','রামগঞ্জ'),(15,'Ramgati','রামগতি'),
-- Noakhali (16)
(16,'Begumganj','বেগমগঞ্জ'),(16,'Chatkhil','চাটখিল'),(16,'Companiganj','কোম্পানীগঞ্জ'),(16,'Hatiya','হাতিয়া'),(16,'Kabirhat','কবিরহাট'),(16,'Noakhali Sadar','নোয়াখালী সদর'),(16,'Senbagh','সেনবাগ'),(16,'Sonaimuri','সোনাইমুড়ী'),(16,'Subarnachar','সুবর্ণচর'),
-- Rangamati (17)
(17,'Bagaichhari','বাঘাইছড়ি'),(17,'Barkal','বরকল'),(17,'Belaichhari','বিলাইছড়ি'),(17,'Juraichhari','জুরাছড়ি'),(17,'Kaptai','কাপ্তাই'),(17,'Kawkhali','কাউখালী'),(17,'Langadu','লংগদু'),(17,'Naniarchar','নানিয়ারচর'),(17,'Rajasthali','রাজস্থলী'),(17,'Rangamati Sadar','রাঙ্গামাটি সদর'),
-- Dhaka (18)
(18,'Dhamrai','ধামরাই'),(18,'Dohar','দোহার'),(18,'Keraniganj','কেরানীগঞ্জ'),(18,'Nawabganj','নবাবগঞ্জ'),(18,'Savar','সাভার'),
-- Faridpur (19)
(19,'Alfadanga','আলফাডাঙ্গা'),(19,'Bhanga','ভাঙ্গা'),(19,'Boalmari','বোয়ালমারী'),(19,'Charbhadrasan','চরভদ্রাসন'),(19,'Faridpur Sadar','ফরিদপুর সদর'),(19,'Madhukhali','মধুখালী'),(19,'Nagarkanda','নগরকান্দা'),(19,'Sadarpur','সদরপুর'),(19,'Saltha','সালথা'),
-- Gazipur (20)
(20,'Gazipur Sadar','গাজীপুর সদর'),(20,'Kaliakair','কালিয়াকৈর'),(20,'Kaliganj','কালীগঞ্জ'),(20,'Kapasia','কাপাসিয়া'),(20,'Sreepur','শ্রীপুর'),
-- Gopalganj (21)
(21,'Gopalganj Sadar','গোপালগঞ্জ সদর'),(21,'Kashiani','কাশিয়ানী'),(21,'Kotalipara','কোটালীপাড়া'),(21,'Muksudpur','মুকসুদপুর'),(21,'Tungipara','টুঙ্গিপাড়া'),
-- Kishoreganj (22)
(22,'Austagram','অষ্টগ্রাম'),(22,'Bajitpur','বাজিতপুর'),(22,'Bhairab','ভৈরব'),(22,'Hossainpur','হোসেনপুর'),(22,'Itna','ইটনা'),(22,'Karimganj','করিমগঞ্জ'),(22,'Katiadi','কটিয়াদী'),(22,'Kishoreganj Sadar','কিশোরগঞ্জ সদর'),(22,'Kuliarchar','কুলিয়ারচর'),(22,'Mithamain','মিঠামইন'),(22,'Nikli','নিকলী'),(22,'Pakundia','পাকুন্দিয়া'),(22,'Tarail','তাড়াইল'),
-- Madaripur (23)
(23,'Kalkini','কালকিনি'),(23,'Madaripur Sadar','মাদারীপুর সদর'),(23,'Rajoir','রাজৈর'),(23,'Shibchar','শিবচর'),(23,'Dasar','ডাসার'),
-- Manikganj (24)
(24,'Daulatpur','দৌলতপুর'),(24,'Ghior','ঘিওর'),(24,'Harirampur','হরিরামপুর'),(24,'Manikganj Sadar','মানিকগঞ্জ সদর'),(24,'Saturia','সাটুরিয়া'),(24,'Shibalaya','শিবালয়'),(24,'Singair','সিংগাইর'),
-- Munshiganj (25)
(25,'Gazaria','গজারিয়া'),(25,'Lohajang','লৌহজং'),(25,'Munshiganj Sadar','মুন্সিগঞ্জ সদর'),(25,'Sirajdikhan','সিরাজদিখান'),(25,'Sreenagar','শ্রীনগর'),(25,'Tongibari','টঙ্গিবাড়ী'),
-- Narayanganj (26)
(26,'Araihazar','আড়াইহাজার'),(26,'Bandar','বন্দর'),(26,'Narayanganj Sadar','নারায়ণগঞ্জ সদর'),(26,'Rupganj','রূপগঞ্জ'),(26,'Sonargaon','সোনারগাঁও'),
-- Narsingdi (27)
(27,'Belabo','বেলাবো'),(27,'Monohardi','মনোহরদী'),(27,'Narsingdi Sadar','নরসিংদী সদর'),(27,'Palash','পলাশ'),(27,'Raipura','রায়পুরা'),(27,'Shibpur','শিবপুর'),
-- Rajbari (28)
(28,'Baliakandi','বালিয়াকান্দি'),(28,'Goalandaghat','গোয়ালন্দ ঘাট'),(28,'Pangsha','পাংশা'),(28,'Rajbari Sadar','রাজবাড়ী সদর'),(28,'Kalukhali','কালুখালী'),
-- Shariatpur (29)
(29,'Bhedarganj','ভেদরগঞ্জ'),(29,'Damudya','ডামুড্যা'),(29,'Gosairhat','গোসাইরহাট'),(29,'Naria','নড়িয়া'),(29,'Shariatpur Sadar','শরীয়তপুর সদর'),(29,'Zajira','জাজিরা'),
-- Tangail (30)
(30,'Basail','বাসাইল'),(30,'Bhuapur','ভূঞাপুর'),(30,'Delduar','দেলদুয়ার'),(30,'Dhanbari','ধনবাড়ী'),(30,'Ghatail','ঘাটাইল'),(30,'Gopalpur','গোপালপুর'),(30,'Kalihati','কালিহাতী'),(30,'Madhupur','মধুপুর'),(30,'Mirzapur','মির্জাপুর'),(30,'Nagarpur','নাগরপুর'),(30,'Sakhipur','সখীপুর'),(30,'Tangail Sadar','টাঙ্গাইল সদর'),(30,'Companiganj','কোম্পানীগঞ্জ'),
-- Bagerhat (31)
(31,'Bagerhat Sadar','বাগেরহাট সদর'),(31,'Chitalmari','চিতলমারী'),(31,'Fakirhat','ফকিরহাট'),(31,'Kachua','কচুয়া'),(31,'Mollahat','মোল্লাহাট'),(31,'Mongla','মোংলা'),(31,'Morrelganj','মোরেলগঞ্জ'),(31,'Rampal','রামপাল'),(31,'Sarankhola','শরণখোলা'),
-- Chuadanga (32)
(32,'Alamdanga','আলমডাঙ্গা'),(32,'Chuadanga Sadar','চুয়াডাঙ্গা সদর'),(32,'Damurhuda','দামুড়হুদা'),(32,'Jibannagar','জীবননগর'),
-- Jashore (33)
(33,'Abhaynagar','অভয়নগর'),(33,'Bagherpara','বাঘারপাড়া'),(33,'Chaugachha','চৌগাছা'),(33,'Jashore Sadar','যশোর সদর'),(33,'Jhikargachha','ঝিকরগাছা'),(33,'Keshabpur','কেশবপুর'),(33,'Manirampur','মনিরামপুর'),(33,'Sharsha','শার্শা'),
-- Jhenaidah (34)
(34,'Harinakunda','হরিণাকুণ্ডু'),(34,'Jhenaidah Sadar','ঝিনাইদহ সদর'),(34,'Kaliganj','কালীগঞ্জ'),(34,'Kotchandpur','কোটচাঁদপুর'),(34,'Maheshpur','মহেশপুর'),(34,'Shailkupa','শৈলকুপা'),
-- Khulna (35)
(35,'Batiaghata','বটিয়াঘাটা'),(35,'Dacope','দাকোপ'),(35,'Dumuria','ডুমুরিয়া'),(35,'Dighalia','দিঘলিয়া'),(35,'Koyra','কয়রা'),(35,'Paikgachha','পাইকগাছা'),(35,'Phultala','ফুলতলা'),(35,'Rupsa','রূপসা'),(35,'Terokhada','তেরখাদা'),
-- Kushtia (36)
(36,'Bheramara','ভেড়ামারা'),(36,'Daulatpur','দৌলতপুর'),(36,'Khoksa','খোকসা'),(36,'Kumarkhali','কুমারখালী'),(36,'Kushtia Sadar','কুষ্টিয়া সদর'),(36,'Mirpur','মিরপুর'),
-- Magura (37)
(37,'Magura Sadar','মাগুরা সদর'),(37,'Mohammadpur','মহম্মদপুর'),(37,'Shalikha','শালিখা'),(37,'Sreepur','শ্রীপুর'),
-- Meherpur (38)
(38,'Gangni','গাংনী'),(38,'Meherpur Sadar','মেহেরপুর সদর'),(38,'Mujibnagar','মুজিবনগর'),
-- Narail (39)
(39,'Kalia','কালিয়া'),(39,'Lohagara','লোহাগড়া'),(39,'Narail Sadar','নড়াইল সদর'),
-- Satkhira (40)
(40,'Assasuni','আশাশুনি'),(40,'Debhata','দেবহাটা'),(40,'Kalaroa','কলারোয়া'),(40,'Kaliganj','কালীগঞ্জ'),(40,'Satkhira Sadar','সাতক্ষীরা সদর'),(40,'Shyamnagar','শ্যামনগর'),(40,'Tala','তালা'),
-- Jamalpur (41)
(41,'Bakshiganj','বকশীগঞ্জ'),(41,'Dewanganj','দেওয়ানগঞ্জ'),(41,'Islampur','ইসলামপুর'),(41,'Jamalpur Sadar','জামালপুর সদর'),(41,'Madarganj','মাদারগঞ্জ'),(41,'Melandaha','মেলান্দহ'),(41,'Sarishabari','সরিষাবাড়ী'),
-- Mymensingh (42)
(42,'Bhaluka','ভালুকা'),(42,'Dhobaura','ধোবাউড়া'),(42,'Fulbaria','ফুলবাড়িয়া'),(42,'Gaffargaon','গফরগাঁও'),(42,'Gauripur','গৌরীপুর'),(42,'Haluaghat','হালুয়াঘাট'),(42,'Ishwarganj','ঈশ্বরগঞ্জ'),(42,'Mymensingh Sadar','ময়মনসিংহ সদর'),(42,'Muktagachha','মুক্তাগাছা'),(42,'Nandail','নান্দাইল'),(42,'Phulpur','ফুলপুর'),(42,'Trishal','ত্রিশাল'),(42,'Tarakanda','তারাকান্দা'),
-- Netrokona (43)
(43,'Atpara','আটপাড়া'),(43,'Barhatta','বারহাট্টা'),(43,'Durgapur','দুর্গাপুর'),(43,'Kalmakanda','কলমাকান্দা'),(43,'Kendua','কেন্দুয়া'),(43,'Khaliajuri','খালিয়াজুরী'),(43,'Madan','মদন'),(43,'Mohanganj','মোহনগঞ্জ'),(43,'Netrokona Sadar','নেত্রকোনা সদর'),(43,'Purbadhala','পূর্বধলা'),
-- Sherpur (44)
(44,'Jhenaigati','ঝিনাইগাতী'),(44,'Nakla','নকলা'),(44,'Nalitabari','নালিতাবাড়ী'),(44,'Sherpur Sadar','শেরপুর সদর'),(44,'Sreebardi','শ্রীবরদী'),
-- Bogura (45)
(45,'Adamdighi','আদমদীঘি'),(45,'Bogura Sadar','বগুড়া সদর'),(45,'Dhunat','ধুনট'),(45,'Dhupchanchia','ধুপচাঁচিয়া'),(45,'Gabtali','গাবতলী'),(45,'Kahaloo','কাহালু'),(45,'Nandigram','নন্দীগ্রাম'),(45,'Sariakandi','সারিয়াকান্দি'),(45,'Shajahanpur','শাজাহানপুর'),(45,'Sherpur','শেরপুর'),(45,'Shibganj','শিবগঞ্জ'),(45,'Sonatola','সোনাতলা'),
-- Chapainawabganj (46)
(46,'Bholahat','ভোলাহাট'),(46,'Gomastapur','গোমস্তাপুর'),(46,'Nachole','নাচোল'),(46,'Chapainawabganj Sadar','চাঁপাইনবাবগঞ্জ সদর'),(46,'Shibganj','শিবগঞ্জ'),
-- Joypurhat (47)
(47,'Akkelpur','আক্কেলপুর'),(47,'Joypurhat Sadar','জয়পুরহাট সদর'),(47,'Kalai','কালাই'),(47,'Khetlal','ক্ষেতলাল'),(47,'Panchbibi','পাঁচবিবি'),
-- Naogaon (48)
(48,'Atrai','আত্রাই'),(48,'Badalgachhi','বদলগাছী'),(48,'Dhamoirhat','ধামইরহাট'),(48,'Manda','মান্দা'),(48,'Mohadevpur','মহাদেবপুর'),(48,'Naogaon Sadar','নওগাঁ সদর'),(48,'Niamatpur','নিয়ামতপুর'),(48,'Patnitala','পত্নীতলা'),(48,'Porsha','পোরশা'),(48,'Raninagar','রাণীনগর'),(48,'Sapahar','সাপাহার'),
-- Natore (49)
(49,'Bagatipara','বাগাতিপাড়া'),(49,'Baraigram','বড়াইগ্রাম'),(49,'Gurudaspur','গুরুদাসপুর'),(49,'Lalpur','লালপুর'),(49,'Natore Sadar','নাটোর সদর'),(49,'Singra','সিংড়া'),
-- Pabna (50)
(50,'Atgharia','আটঘরিয়া'),(50,'Bera','বেড়া'),(50,'Bhangura','ভাঙ্গুড়া'),(50,'Chatmohar','চাটমোহর'),(50,'Faridpur','ফরিদপুর'),(50,'Ishwardi','ঈশ্বরদী'),(50,'Pabna Sadar','পাবনা সদর'),(50,'Santhia','সাঁথিয়া'),(50,'Sujanagar','সুজানগর'),
-- Rajshahi (51)
(51,'Bagha','বাঘা'),(51,'Bagmara','বাগমারা'),(51,'Charghat','চারঘাট'),(51,'Durgapur','দুর্গাপুর'),(51,'Godagari','গোদাগাড়ী'),(51,'Mohanpur','মোহনপুর'),(51,'Paba','পবা'),(51,'Puthia','পুঠিয়া'),(51,'Tanore','তানোর'),
-- Sirajganj (52)
(52,'Belkuchi','বেলকুচি'),(52,'Chauhali','চৌহালী'),(52,'Kamarkhanda','কামারখন্দ'),(52,'Kazipur','কাজীপুর'),(52,'Raiganj','রায়গঞ্জ'),(52,'Shahjadpur','শাহজাদপুর'),(52,'Sirajganj Sadar','সিরাজগঞ্জ সদর'),(52,'Tarash','তাড়াশ'),(52,'Ullapara','উল্লাপাড়া'),
-- Dinajpur (53)
(53,'Birampur','বিরামপুর'),(53,'Birganj','বীরগঞ্জ'),(53,'Biral','বিরল'),(53,'Bochaganj','বোচাগঞ্জ'),(53,'Chirirbandar','চিরিরবন্দর'),(53,'Phulbari','ফুলবাড়ী'),(53,'Ghoraghat','ঘোড়াঘাট'),(53,'Hakimpur','হাকিমপুর'),(53,'Kaharole','কাহারোল'),(53,'Khansama','খানসামা'),(53,'Dinajpur Sadar','দিনাজপুর সদর'),(53,'Nawabganj','নবাবগঞ্জ'),(53,'Parbatipur','পার্বতীপুর'),
-- Gaibandha (54)
(54,'Fulchhari','ফুলছড়ি'),(54,'Gaibandha Sadar','গাইবান্ধা সদর'),(54,'Gobindaganj','গোবিন্দগঞ্জ'),(54,'Palashbari','পলাশবাড়ী'),(54,'Sadullapur','সাদুল্লাপুর'),(54,'Saghata','সাঘাটা'),(54,'Sundarganj','সুন্দরগঞ্জ'),
-- Kurigram (55)
(55,'Bhurungamari','ভুরুঙ্গামারী'),(55,'Char Rajibpur','চর রাজিবপুর'),(55,'Chilmari','চিলমারী'),(55,'Kurigram Sadar','কুড়িগ্রাম সদর'),(55,'Nageshwari','নাগেশ্বরী'),(55,'Phulbari','ফুলবাড়ী'),(55,'Rajarhat','রাজারহাট'),(55,'Raomari','রৌমারী'),(55,'Ulipur','উলিপুর'),
-- Lalmonirhat (56)
(56,'Aditmari','আদিতমারী'),(56,'Hatibandha','হাতীবান্ধা'),(56,'Kaliganj','কালীগঞ্জ'),(56,'Lalmonirhat Sadar','লালমনিরহাট সদর'),(56,'Patgram','পাটগ্রাম'),
-- Nilphamari (57)
(57,'Dimla','ডিমলা'),(57,'Domar','ডোমার'),(57,'Jaldhaka','জলঢাকা'),(57,'Kishoreganj','কিশোরগঞ্জ'),(57,'Nilphamari Sadar','নীলফামারী সদর'),(57,'Saidpur','সৈয়দপুর'),
-- Panchagarh (58)
(58,'Atwari','আটোয়ারী'),(58,'Boda','বোদা'),(58,'Debiganj','দেবীগঞ্জ'),(58,'Panchagarh Sadar','পঞ্চগড় সদর'),(58,'Tetulia','তেঁতুলিয়া'),
-- Rangpur (59)
(59,'Badarganj','বদরগঞ্জ'),(59,'Gangachara','গঙ্গাচড়া'),(59,'Kaunia','কাউনিয়া'),(59,'Mithapukur','মিঠাপুকুর'),(59,'Pirgachha','পীরগাছা'),(59,'Pirganj','পীরগঞ্জ'),(59,'Rangpur Sadar','রংপুর সদর'),(59,'Taraganj','তারাগঞ্জ'),
-- Thakurgaon (60)
(60,'Baliadangi','বালিয়াডাঙ্গী'),(60,'Haripur','হরিপুর'),(60,'Pirganj','পীরগঞ্জ'),(60,'Ranisankail','রাণীশংকৈল'),(60,'Thakurgaon Sadar','ঠাকুরগাঁও সদর'),
-- Habiganj (61)
(61,'Ajmiriganj','আজমিরীগঞ্জ'),(61,'Bahubal','বাহুবল'),(61,'Baniachong','বানিয়াচং'),(61,'Chunarughat','চুনারুঘাট'),(61,'Habiganj Sadar','হবিগঞ্জ সদর'),(61,'Lakhai','লাখাই'),(61,'Madhabpur','মাধবপুর'),(61,'Nabiganj','নবীগঞ্জ'),(61,'Shayestaganj','শায়েস্তাগঞ্জ'),
-- Moulvibazar (62)
(62,'Barlekha','বড়লেখা'),(62,'Juri','জুড়ী'),(62,'Kamalganj','কমলগঞ্জ'),(62,'Kulaura','কুলাউড়া'),(62,'Moulvibazar Sadar','মৌলভীবাজার সদর'),(62,'Rajnagar','রাজনগর'),(62,'Sreemangal','শ্রীমঙ্গল'),
-- Sunamganj (63)
(63,'Bishwamvarpur','বিশ্বম্ভরপুর'),(63,'Chhatak','ছাতক'),(63,'Derai','দিরাই'),(63,'Dharampasha','ধরমপাশা'),(63,'Dowarabazar','দোয়ারাবাজার'),(63,'Jagannathpur','জগন্নাথপুর'),(63,'Jamalganj','জামালগঞ্জ'),(63,'Sullah','সুল্লা'),(63,'Sunamganj Sadar','সুনামগঞ্জ সদর'),(63,'Tahirpur','তাহিরপুর'),(63,'South Sunamganj','দক্ষিণ সুনামগঞ্জ'),
-- Sylhet (64)
(64,'Balaganj','বালাগঞ্জ'),(64,'Beanibazar','বিয়ানীবাজার'),(64,'Bishwanath','বিশ্বনাথ'),(64,'Companiganj','কোম্পানীগঞ্জ'),(64,'Fenchuganj','ফেঞ্চুগঞ্জ'),(64,'Golapganj','গোলাপগঞ্জ'),(64,'Gowainghat','গোয়াইনঘাট'),(64,'Jaintiapur','জৈন্তাপুর'),(64,'Kanaighat','কানাইঘাট'),(64,'Osmani Nagar','ওসমানীনগর'),(64,'Sylhet Sadar','সিলেট সদর'),(64,'Zakiganj','জকিগঞ্জ');
