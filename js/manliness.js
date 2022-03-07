// reference: https://twitter.com/holistic_moron/status/1387349365510090752
// https://wordhelp.ru/
// https://docs.google.com/spreadsheets/d/1uOQOnRF6fDwnN3rPvECSnTQIlxjOamvV-iJ2v6XmVAg/edit?usp=sharing

function swapTitle() {
    const e = document.getElementById("title-span");
    if (e.innerText === "Муженизатор") {
        e.innerText = "Угнетатор"
    } else {
        e.innerText = "Муженизатор"
    }
}

function makeManlier(word, wcase = undefined, plural = undefined) {
    let words = [];
    femEndings.forEach(function (item, i, arr) {
        if (new RegExp(item[0] + "$").test(word)) {
            if (plural !== undefined && item[3] !== undefined && item[3] !== plural) {
                return;
            }
            if (wcase !== undefined && item[4] !== undefined && item[4] !== wcase) {
                return;
            }
            let endings = item[1].split(',')
            endings.forEach(function (ending) {
                words.push(word.substr(0, word.length - item[2]) + ending)

            });
        }
    });
    return words
}

function onlyUnique(value, index, self) {
    return self.indexOf(value) === index;
}

function showToUser(initialWord, words) {
    if (words.length === 0) {
        // word already manly enough
        document.getElementById("message-span").innerHTML =
            "Это слово уже выглядит достаточно мужественным"
        document.getElementById("man-word-span").innerText = initialWord
    } else {
        let otherWords = "";
        for (let i = 1; i < words.length; i++) {
            otherWords += words[i];
            if (i !== words.length - 1) {
                otherWords += ", ";
            }
        }
        document.getElementById("message-span").innerHTML = otherWords;
        document.getElementById("man-word-span").innerText = words[0];
    }
}

function requestFromDictionary() {
    let word = document.getElementById("word-input").value.toLowerCase()
    if (!new RegExp("^[а-яё\-]+$").test(word)) {
        document.getElementById("message-span").innerHTML =
            "Введите одно слово на русском языке"
        return;
    }
    let manOnly = false;
    let wordsToReturn = [];
    const url = '../api.php?word=' + word;
    $.ajax({
        url: url,
        type: 'GET',
        dataType: 'json', // added data type
        success: function (res) {
            let responseOk = res['ok'];
            if (!responseOk) {
                onApiError(res['error'])
                return
            }
            /** @type array*/
            let wordsFromResponse = res['words']
            if (wordsFromResponse.length > 0)
                manOnly = true;
            wordsFromResponse.forEach(function (item, i, arr) {
                console.log(item['gender'])
                console.log(item['gender'])
                if (item['gender'] !== 'муж') {
                    wordsToReturn = wordsToReturn.concat(makeManlier(word, item['wcase'], item['plural']));
                    manOnly = false;
                }
            });
        },
        error: function (jqXHR, exception) {
            let msg = '';
            if (jqXHR.status === 0) {
                msg = 'Not connect.\n Verify Network.';
            } else if (jqXHR.status === 404) {
                msg = 'Requested page not found. [404]';
            } else if (jqXHR.status === 500) {
                msg = 'Internal Server Error [500].';
            } else if (exception === 'parsererror') {
                msg = 'Requested JSON parse failed.';
            } else if (exception === 'timeout') {
                msg = 'Time out error.';
            } else if (exception === 'abort') {
                msg = 'Ajax request aborted.';
            } else {
                msg = 'Uncaught Error.\n' + jqXHR.responseText;
            }
            onAjaxError(msg)
        },
        complete: function (data) {
            // try to get something without wcase and plural
            // Если записи найдены в словаре, но они все мужского рода, то муженизировать ничего не надо
            if (wordsToReturn.length === 0 && !manOnly) {
                wordsToReturn = wordsToReturn.concat(makeManlier(word));
            }

            wordsToReturn = wordsToReturn.filter(onlyUnique).filter(function (item) {
                // remove source word and word with length<2 as it makes no sense
                return item.length >= 2 && item !== word
            })
            showToUser(word, wordsToReturn);
        }
    });
}

// this errors happens when encountered issue with getting json object
function onAjaxError(msg) {
    // todo
}

// this errors happens when received json with "ok: false"
function onApiError(msg) {
    // todo
}