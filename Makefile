.PHONY: build clean test

build:
	php -dphar.readonly=0 vendor/bin/box compile

clean:
	rm -f *.phar *.progress *.debug.txt
	rm -rf vendor/

test:
	php translate.php --input=test.srt --language=German --model=glm-4.7-flash
